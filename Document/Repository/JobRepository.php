<?php

/*
 * Copyright 2012 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Eo\JobQueueBundle\Document\Repository;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\DBAL\Types\Type;
use Doctrine\ODM\MongoDB\DocumentRepository;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use JMS\DiExtraBundle\Annotation as DI;
use Eo\JobQueueBundle\Document\Job;
use Eo\JobQueueBundle\Document\JobInterface;
use Eo\JobQueueBundle\Event\StateChangeEvent;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use DateTime;
use DateInterval;

class JobRepository extends DocumentRepository
{
    private $dispatcher;
    private $registry;

    /**
     * @DI\InjectParams({
     *     "dispatcher" = @DI\Inject("event_dispatcher"),
     * })
     * @param EventDispatcherInterface $dispatcher
     */
    public function setDispatcher(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * @DI\InjectParams({
     *     "registry" = @DI\Inject("doctrine"),
     * })
     * @param RegistryInterface $registry
     */
    public function setRegistry(RegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    public function findJob($command, array $args = array())
    {
        return $this->createQueryBuilder()
            ->field('command')->equals($command)
            ->field('args')->equals($args)
            ->limit(1)
            ->getQuery()
            ->getSingleResult();
    }

    public function getJob($command, array $args = array())
    {
        if (null !== $job = $this->findJob($command, $args)) {
            return $job;
        }

        throw new \RuntimeException(sprintf('Found no job for command "%s" with args "%s".', $command, json_encode($args)));
    }

    public function getOrCreateIfNotExists($command, array $args = array())
    {
        if (null !== $job = $this->findJob($command, $args)) {
            return $job;
        }

        $job = new Job($command, $args, false);
        $this->dm->persist($job);
        $this->dm->flush($job);

        $firstJob = $this->dm->createQuery("SELECT j FROM EoJobQueueBundle:Job j WHERE j.command = :command AND j.args = :args ORDER BY j.id ASC")
             ->setParameter('command', $command)
             ->setParameter('args', $args, 'json_array')
             ->setMaxResults(1)
             ->getSingleResult();

        if ($firstJob === $job) {
            $job->setState(Job::STATE_PENDING);
            $this->dm->persist($job);
            $this->dm->flush($job);

            return $job;
        }

        $this->dm->remove($job);
        $this->dm->flush($job);

        return $firstJob;
    }

    public function findStartableJob(array &$excludedIds = array())
    {
        while (null !== $job = $this->findPendingJob($excludedIds)) {
            if ($job->isStartable()) {
                return $job;
            }

            $excludedIds[] = $job->getId();

            // We do not want to have non-startable jobs floating around in
            // cache as they might be changed by another process. So, better
            // re-fetch them when they are not excluded anymore.
            $this->dm->detach($job);
        }

        return null;
    }

    public function findAllForRelatedDocument($relatedDocument)
    {
        list($relClass, $relId) = $this->getRelatedDocumentIdentifier($relatedDocument);

        $rsm = new ResultSetMappingBuilder($this->dm);
        $rsm->addRootDocumentFromClassMetadata('EoJobQueueBundle:Job', 'j');

        return $this->dm->createNativeQuery("SELECT j.* FROM eo_jobs j INNER JOIN eo_job_related_documents r ON r.job_id = j.id WHERE r.related_class = :relClass AND r.related_id = :relId", $rsm)
                    ->setParameter('relClass', $relClass)
                    ->setParameter('relId', $relId)
                    ->getResult();
    }

    public function findJobForRelatedDocument($command, $relatedDocument)
    {
        list($relClass, $relId) = $this->getRelatedDocumentIdentifier($relatedDocument);

        $rsm = new ResultSetMappingBuilder($this->dm);
        $rsm->addRootDocumentFromClassMetadata('EoJobQueueBundle:Job', 'j');

        return $this->dm->createNativeQuery("SELECT j.* FROM eo_jobs j INNER JOIN eo_job_related_documents r ON r.job_id = j.id WHERE r.related_class = :relClass AND r.related_id = :relId AND j.command = :command", $rsm)
                   ->setParameter('command', $command)
                   ->setParameter('relClass', $relClass)
                   ->setParameter('relId', $relId)
                   ->getOneOrNullResult();
    }

    private function getRelatedDocumentIdentifier($document)
    {
        assert('is_object($document)');

        if ($document instanceof \Doctrine\Common\Persistence\Proxy) {
            $document->__load();
        }

        $relClass = ClassUtils::getClass($document);
        $relId = $this->registry->getManagerForClass($relClass)->getMetadataFactory()
                    ->getMetadataFor($relClass)->getIdentifierValues($document);
        asort($relId);

        if ( ! $relId) {
            throw new \InvalidArgumentException(sprintf('The identifier for document of class "%s" was empty.', $relClass));
        }

        return array($relClass, json_encode($relId));
    }

    public function findPendingJob(array $excludedIds = array())
    {
        if ( ! $excludedIds) {
            $excludedIds = array(-1);
        }

        return $this->createQueryBuilder()
                    ->field('id')->notIn(array($excludedIds))
                    ->field('state')->equals(Job::STATE_PENDING)
                    ->field('executeAfter')->lt(new DateTime())
                    ->sort('createdAt', 'desc')
                    ->limit(1)
                    ->getQuery()
                    ->getSingleResult();
    }

    public function closeJob(JobInterface $job, $finalState)
    {
        $visited = array();
        $this->closeJobInternal($job, $finalState, $visited);

        // Clean-up document manager to allow for garbage collection to kick in.
        foreach ($visited as $job) {
            // If the job is an original job which is now being retried, let's
            // not remove it just yet.
            if ( ! $job->isClosedNonSuccessful() || $job->isRetryJob()) {
                continue;
            }

            $this->dm->detach($job);
        }
    }

    private function closeJobInternal(JobInterface $job, $finalState, array &$visited = array())
    {
        if (in_array($job, $visited, true)) {
            return;
        }
        $visited[] = $job;

        if (null !== $this->dispatcher && ($job->isRetryJob() || 0 === count($job->getRetryJobs()))) {
            $event = new StateChangeEvent($job, $finalState);
            $this->dispatcher->dispatch('eo_job_queue.job_state_change', $event);
            $finalState = $event->getNewState();
        }

        switch ($finalState) {
            case Job::STATE_CANCELED:
                $job->setState(Job::STATE_CANCELED);
                $this->dm->persist($job);
                $this->dm->flush();

                if ($job->isRetryJob()) {
                    $this->closeJobInternal($job->getOriginalJob(), Job::STATE_CANCELED, $visited);

                    return;
                }

                foreach ($this->findIncomingDependencies($job) as $dep) {
                    $this->closeJobInternal($dep, Job::STATE_CANCELED, $visited);
                }

                return;

            case Job::STATE_FAILED:
            case Job::STATE_TERMINATED:
            case Job::STATE_INCOMPLETE:
                if ($job->isRetryJob()) {
                    $job->setState($finalState);
                    $this->dm->persist($job);
                    $this->dm->flush();

                    $this->closeJobInternal($job->getOriginalJob(), $finalState);

                    return;
                }

                // The original job has failed, and we are allowed to retry it.
                if ($job->isRetryAllowed()) {
                    $retryJob = new Job($job->getCommand(), $job->getArgs());
                    $retryJob->setMaxRuntime($job->getMaxRuntime());
                    $job->addRetryJob($retryJob);
                    $this->dm->persist($retryJob);
                    $this->dm->persist($job);
                    $this->dm->flush();
                    return;
                }

                $job->setState($finalState);
                $this->dm->persist($job);
                $this->dm->flush();

                // The original job has failed, and no retries are allowed.
                foreach ($this->findIncomingDependencies($job) as $dep) {
                    $this->closeJobInternal($dep, Job::STATE_CANCELED, $visited);
                }

                return;

            case Job::STATE_FINISHED:
                if ($job->isRetryJob()) {
                    $job->getOriginalJob()->setState($finalState);
                    $this->getDocumentManager()->persist($job->getOriginalJob());
                }

                $job->setState($finalState);
                $this->dm->persist($job);
                $this->dm->flush();

                if (!is_null($job->getInterval())) {
                    $newJob = clone $job;
                    $newJob->setExecuteAfter(new DateTime("+" . $job->getInterval() . " seconds"));
                    $this->dm->persist($newJob);
                    $this->dm->flush();
                }
                return;

            default:
                throw new \LogicException(sprintf('Non allowed state "%s" in closeJobInternal().', $finalState));
        }
    }

    public function findIncomingDependencies(JobInterface $job)
    {
        return $job->getDependencies();
    }

    public function getIncomingDependencies(JobInterface $job)
    {
        return $job->getDependencies();
    }

    public function findLastJobsWithError($nbJobs = 10)
    {
        return $this->createQueryBuilder()
                    ->field('state')->in(array(Job::STATE_TERMINATED, Job::STATE_FAILED))
                    ->field('originalJob')->equals(null)
                    ->sort('closedAt', 'DESC')
                    ->limit($nbJobs)
                    ->getQuery()
                    ->execute();
    }
}