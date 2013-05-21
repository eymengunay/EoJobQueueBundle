<?php

namespace Eo\JobQueueBundle\Controller;

use Doctrine\Common\Util\ClassUtils;
use JMS\DiExtraBundle\Annotation as DI;
use Eo\JobQueueBundle\Entity\Job;
use Pagerfanta\Adapter\DoctrineODMMongoDBAdapter;
use Pagerfanta\Pagerfanta;
use Pagerfanta\View\TwitterBootstrapView;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class ODMJobController extends Controller
{
    /**
     * @Route("/", name = "eo_jobs_overview")
     * @Template("EoJobQueueBundle:Job:overview.html.twig")
     */
    public function overviewAction()
    {
        $lastJobsWithError = $this->getRepo()->findLastJobsWithError(5);

        $qb = $this->getDm()->createQueryBuilder($this->getJobClass());
        $qb->field('originalJob')->equals(null)
            ->sort('createdAt', 'desc');

        foreach ($lastJobsWithError as $i => $job) {
            $qb->field('originalJob')->notEqual($i);
        }

        $request = $this->container->get('request');
        $router = $this->container->get('router');

        $pager = new Pagerfanta(new DoctrineODMMongoDBAdapter($qb));
        $pager->setCurrentPage(max(1, (integer) $request->query->get('page', 1)));
        $pager->setMaxPerPage(max(5, min(50, (integer) $request->query->get('per_page', 20))));

        $pagerView = new TwitterBootstrapView();
        $routeGenerator = function($page) use ($router, $pager) {
            return $router->generate('eo_jobs_overview', array('page' => $page, 'per_page' => $pager->getMaxPerPage()));
        };

        return array(
            'jobsWithError' => $lastJobsWithError,
            'jobPager' => $pager,
            'jobPagerView' => $pagerView,
            'jobPagerGenerator' => $routeGenerator,
        );
    }

    /**
     * @Route("/{id}", name = "eo_jobs_details")
     * @Template
     */
    public function detailsAction(Job $job)
    {
        $relatedEntities = array();
        foreach ($job->getRelatedEntities() as $entity) {
            $class = ClassUtils::getClass($entity);
            $relatedEntities[] = array(
                'class' => $class,
                'id' => json_encode($this->getDm()->getClassMetadata($class)->getIdentifierValues($entity)),
                'raw' => $entity,
            );
        }

        $statisticData = $statisticOptions = array();
        if ($this->statisticsEnabled) {
            $dataPerCharacteristic = array();
            foreach ($this->getDm()->getConnection()->query("SELECT * FROM eo_job_statistics WHERE job_id = ".$job->getId()) as $row) {
                $dataPerCharacteristic[$row['characteristic']][] = array(
                    $row['createdAt'],
                    $row['charValue'],
                );
            }

            if ($dataPerCharacteristic) {
                $statisticData = array(array_merge(array('Time'), $chars = array_keys($dataPerCharacteristic)));
                $startTime = strtotime($dataPerCharacteristic[$chars[0]][0][0]);
                $endTime = strtotime($dataPerCharacteristic[$chars[0]][count($dataPerCharacteristic[$chars[0]])-1][0]);
                $scaleFactor = $endTime - $startTime > 300 ? 1/60 : 1;

                // This assumes that we have the same number of rows for each characteristic.
                for ($i=0,$c=count(reset($dataPerCharacteristic)); $i<$c; $i++) {
                    $row = array((strtotime($dataPerCharacteristic[$chars[0]][$i][0]) - $startTime) * $scaleFactor);
                    foreach ($chars as $name) {
                        $value = (float) $dataPerCharacteristic[$name][$i][1];

                        switch ($name) {
                            case 'memory':
                                $value /= 1024 * 1024;
                                break;
                        }

                        $row[] = $value;
                    }

                    $statisticData[] = $row;
                }
            }
        }

        return array(
            'job' => $job,
            'relatedEntities' => $relatedEntities,
            'incomingDependencies' => $this->getRepo()->getIncomingDependencies($job),
            'statisticData' => $statisticData,
            'statisticOptions' => $statisticOptions,
        );
    }

    /**
     * @Route("/{id}/retry", name = "eo_jobs_retry_job")
     */
    public function retryJobAction(Job $job)
    {
        $state = $job->getState();

        if (
            Job::STATE_FAILED !== $state &&
            Job::STATE_TERMINATED !== $state &&
            Job::STATE_INCOMPLETE !== $state
        ) {
            throw new HttpException(400, 'Given job can\'t be retried');
        }

        $retryJob = clone $job;

        $this->getDm()->persist($retryJob);
        $this->getDm()->flush();

        $url = $this->router->generate('eo_jobs_details', array('id' => $retryJob->getId()), false);

        return new RedirectResponse($url, 201);
    }

    /** @return \Doctrine\ORM\EntityManager */
    private function getDm()
    {
        return $this->container->get('doctrine_mongodb')->getManagerForClass($this->getJobClass());
    }

    /** @return \Eo\JobQueueBundle\Entity\Repository\JobRepository */
    private function getRepo()
    {
        return $this->getDm()->getRepository($this->getJobClass());
    }

    private function getJobClass()
    {
        return $this->container->getParameter('eo_job_queue.job_class');
    }
}
