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

namespace Eo\JobQueueBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Eo\JobQueueBundle\Exception\InvalidStateTransitionException;
use Eo\JobQueueBundle\Exception\LogicException;
use Eo\JobQueueBundle\Model\Job as BaseJob;
use Eo\JobQueueBundle\Model\JobInterface;
use Symfony\Component\HttpKernel\Exception\FlattenException;

/**
 * @ORM\Entity(repositoryClass = "Eo\JobQueueBundle\Entity\Repository\JobRepository")
 * @ORM\Table(name = "eo_jobs", indexes = {
 *     @ORM\Index(columns = {"command"}),
 * })
 * @ORM\ChangeTrackingPolicy("DEFERRED_EXPLICIT")
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class Job extends BaseJob implements JobInterface
{
    /** State if job is inserted, but not yet ready to be started. */
    const STATE_NEW = 'new';

    /**
     * State if job is inserted, and might be started.
     *
     * It is important to note that this does not automatically mean that all
     * jobs of this state can actually be started, but you have to check
     * isStartable() to be absolutely sure.
     *
     * In contrast to NEW, jobs of this state at least might be started,
     * while jobs of state NEW never are allowed to be started.
     */
    const STATE_PENDING = 'pending';

    /** State if job was never started, and will never be started. */
    const STATE_CANCELED = 'canceled';

    /** State if job was started and has not exited, yet. */
    const STATE_RUNNING = 'running';

    /** State if job exists with a successful exit code. */
    const STATE_FINISHED = 'finished';

    /** State if job exits with a non-successful exit code. */
    const STATE_FAILED = 'failed';

    /** State if job exceeds its configured maximum runtime. */
    const STATE_TERMINATED = 'terminated';

    /**
     * State if an error occurs in the runner command.
     *
     * The runner command is the command that actually launches the individual
     * jobs. If instead an error occurs in the job command, this will result
     * in a state of FAILED.
     */
    const STATE_INCOMPLETE = 'incomplete';

    /** @ORM\Id @ORM\GeneratedValue(strategy = "AUTO") @ORM\Column(type = "bigint", options = {"unsigned": true}) */
    protected $id;

    /** @ORM\Column(type = "string") */
    protected $state;

    /** @ORM\Column(type = "datetime") */
    protected $createdAt;

    /** @ORM\Column(type = "datetime", nullable = true) */
    protected $startedAt;

    /** @ORM\Column(type = "datetime", nullable = true) */
    protected $checkedAt;

    /** @ORM\Column(type = "datetime", nullable = true) */
    protected $executeAfter;

    /** @ORM\Column(type = "datetime", nullable = true) */
    protected $closedAt;

    /** @ORM\Column(type = "string") */
    protected $command;

    /** @ORM\Column(type = "json_array") */
    protected $args;

    /**
     * @ORM\ManyToMany(targetEntity = "Job", fetch = "EAGER")
     * @ORM\JoinTable(name="eo_job_dependencies",
     *     joinColumns = { @ORM\JoinColumn(name = "source_job_id", referencedColumnName = "id") },
     *     inverseJoinColumns = { @ORM\JoinColumn(name = "dest_job_id", referencedColumnName = "id")}
     * )
     */
    protected $dependencies;

    /** @ORM\Column(type = "text", nullable = true) */
    protected $output;

    /** @ORM\Column(type = "text", nullable = true) */
    protected $errorOutput;

    /** @ORM\Column(type = "smallint", nullable = true, options = {"unsigned": true}) */
    protected $exitCode;

    /** @ORM\Column(type = "smallint", options = {"unsigned": true}) */
    protected $maxRuntime = 0;

    /** @ORM\Column(type = "smallint", options = {"unsigned": true}) */
    protected $maxRetries = 0;

    /** @ORM\ManyToOne(targetEntity = "Job", inversedBy = "retryJobs") */
    protected $originalJob;

    /** @ORM\OneToMany(targetEntity = "Job", mappedBy = "originalJob", cascade = {"persist", "remove", "detach"}) */
    protected $retryJobs;

    /** @ORM\Column(type = "object", nullable = true) */
    protected $stackTrace;

    /** @ORM\Column(type = "smallint", nullable = true, options = {"unsigned": true}) */
    protected $runtime;

    /** @ORM\Column(type = "integer", nullable = true, options = {"unsigned": true}) */
    protected $memoryUsage;

    /** @ORM\Column(type = "integer", nullable = true, options = {"unsigned": true}) */
    protected $memoryUsageReal;

    /**
     * This may store any entities which are related to this job, and are
     * managed by Doctrine.
     *
     * It is effectively a many-to-any association.
     */
    protected $relatedEntities;

    public static function create($command, array $args = array(), $confirmed = true)
    {
        return new self($command, $args, $confirmed);
    }

    public static function isNonSuccessfulFinalState($state)
    {
        return in_array($state, array(self::STATE_CANCELED, self::STATE_FAILED, self::STATE_INCOMPLETE, self::STATE_TERMINATED), true);
    }

    public function __construct($command, array $args = array(), $confirmed = true)
    {
        $this->command = $command;
        $this->args = $args;
        $this->state = $confirmed ? self::STATE_PENDING : self::STATE_NEW;
        $this->createdAt = new \DateTime();
        $this->executeAfter = new \DateTime();
        $this->executeAfter = $this->executeAfter->modify('-1 second');
        $this->dependencies = new ArrayCollection();
        $this->retryJobs = new ArrayCollection();
        $this->relatedEntities = new ArrayCollection();
    }

    public function __clone()
    {
        $this->state = self::STATE_PENDING;
        $this->createdAt = new \DateTime();
        $this->startedAt = null;
        $this->checkedAt = null;
        $this->closedAt = null;
        $this->output = null;
        $this->errorOutput = null;
        $this->exitCode = null;
        $this->stackTrace = null;
        $this->runtime = null;
        $this->memoryUsage = null;
        $this->memoryUsageReal = null;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getState()
    {
        return $this->state;
    }

    public function isStartable()
    {
        foreach ($this->dependencies as $dep) {
            if ($dep->getState() !== self::STATE_FINISHED) {
                return false;
            }
        }

        return true;
    }

    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    public function getClosedAt()
    {
        return $this->closedAt;
    }

    public function getExecuteAfter()
    {
        return $this->executeAfter;
    }

    public function setExecuteAfter(\DateTime $executeAfter)
    {
        $this->executeAfter = $executeAfter;
    }

    public function getCommand()
    {
        return $this->command;
    }

    public function getArgs()
    {
        return $this->args;
    }

    public function getRelatedEntities()
    {
        return $this->relatedEntities;
    }

    public function isClosedNonSuccessful()
    {
        return self::isNonSuccessfulFinalState($this->state);
    }

    public function findRelatedEntity($class)
    {
        foreach ($this->relatedEntities as $entity) {
            if ($entity instanceof $class) {
                return $entity;
            }
        }

        return null;
    }

    public function addRelatedEntity($entity)
    {
        assert('is_object($entity)');

        if ($this->relatedEntities->contains($entity)) {
            return;
        }

        $this->relatedEntities->add($entity);
    }

    public function getDependencies()
    {
        return $this->dependencies;
    }

    public function hasDependency(JobInterface $job)
    {
        return $this->dependencies->contains($job);
    }

    public function addDependency(JobInterface $job)
    {
        if ($this->dependencies->contains($job)) {
            return;
        }

        if ($this->mightHaveStarted()) {
            throw new \LogicException('You cannot add dependencies to a job which might have been started already.');
        }

        $this->dependencies->add($job);
    }

    public function getRuntime()
    {
        return $this->runtime;
    }

    public function setRuntime($time)
    {
        $this->runtime = (integer) $time;
    }

    public function getMemoryUsage()
    {
        return $this->memoryUsage;
    }

    public function getMemoryUsageReal()
    {
        return $this->memoryUsageReal;
    }

    public function addOutput($output)
    {
        $this->output .= $output;
    }

    public function addErrorOutput($output)
    {
        $this->errorOutput .= $output;
    }

    public function setOutput($output)
    {
        $this->output = $output;
    }

    public function setErrorOutput($output)
    {
        $this->errorOutput = $output;
    }

    public function getOutput()
    {
        return $this->output;
    }

    public function getErrorOutput()
    {
        return $this->errorOutput;
    }

    public function setExitCode($code)
    {
        $this->exitCode = $code;
    }

    public function getExitCode()
    {
        return $this->exitCode;
    }

    public function setMaxRuntime($time)
    {
        $this->maxRuntime = (integer) $time;
    }

    public function getMaxRuntime()
    {
        return $this->maxRuntime;
    }

    public function getStartedAt()
    {
        return $this->startedAt;
    }

    public function getMaxRetries()
    {
        return $this->maxRetries;
    }

    public function setMaxRetries($tries)
    {
        $this->maxRetries = (integer) $tries;
    }

    public function isRetryAllowed()
    {
        // If no retries are allowed, we can bail out directly, and we
        // do not need to initialize the retryJobs relation.
        if (0 === $this->maxRetries) {
            return false;
        }

        return count($this->retryJobs) < $this->maxRetries;
    }

    public function getOriginalJob()
    {
        if (null === $this->originalJob) {
            return $this;
        }

        return $this->originalJob;
    }

    public function setOriginalJob(JobInterface $job)
    {
        if (self::STATE_PENDING !== $this->state) {
            throw new \LogicException($this.' must be in state "PENDING".');
        }

        if (null !== $this->originalJob) {
            throw new \LogicException($this.' already has an original job set.');
        }

        $this->originalJob = $job;
    }

    public function addRetryJob(JobInterface $job)
    {
        if (self::STATE_RUNNING !== $this->state) {
            throw new \LogicException('Retry jobs can only be added to running jobs.');
        }

        $job->setOriginalJob($this);
        $this->retryJobs->add($job);
    }

    public function getRetryJobs()
    {
        return $this->retryJobs;
    }

    public function isRetryJob()
    {
        return null !== $this->originalJob;
    }

    public function checked()
    {
        $this->checkedAt = new \DateTime();
    }

    public function getCheckedAt()
    {
        return $this->checkedAt;
    }

    public function setStackTrace(FlattenException $ex)
    {
        $this->stackTrace = $ex;
    }

    public function getStackTrace()
    {
        return $this->stackTrace;
    }

    public function isNew()
    {
        return self::STATE_NEW === $this->state;
    }

    public function isPending()
    {
        return self::STATE_PENDING === $this->state;
    }

    public function isCanceled()
    {
        return self::STATE_CANCELED === $this->state;
    }

    public function isRunning()
    {
        return self::STATE_RUNNING === $this->state;
    }

    public function isTerminated()
    {
        return self::STATE_TERMINATED === $this->state;
    }

    public function isFailed()
    {
        return self::STATE_FAILED === $this->state;
    }

    public function isFinished()
    {
        return self::STATE_FINISHED === $this->state;
    }

    public function isIncomplete()
    {
        return self::STATE_INCOMPLETE === $this->state;
    }

    public function __toString()
    {
        return sprintf('Job(id = %s, command = "%s")', $this->id, $this->command);
    }

    protected function mightHaveStarted()
    {
        if (null === $this->id) {
            return false;
        }

        if (self::STATE_NEW === $this->state) {
            return false;
        }

        if (self::STATE_PENDING === $this->state && ! $this->isStartable()) {
            return false;
        }

        return true;
    }
}
