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

namespace Eo\JobQueueBundle\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Eo\JobQueueBundle\Exception\InvalidStateTransitionException;
use Eo\JobQueueBundle\Exception\LogicException;
use Eo\JobQueueBundle\Model\JobInterface;
use Symfony\Component\HttpKernel\Exception\FlattenException;

/**
 * Eo\JobQueueBundle\Model\Job
 *
 * @author Eymen Gunay <eymen@egunay.com>
 */
class Job
{
    protected $state;

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

    public function setState($newState)
    {
        if ($newState === $this->state) {
            return;
        }

        switch ($this->state) {
            case self::STATE_NEW:
                if ( ! in_array($newState, array(self::STATE_PENDING, self::STATE_CANCELED), true)) {
                    throw new InvalidStateTransitionException($this, $newState, array(self::STATE_PENDING, self::STATE_CANCELED));
                }

                if (self::STATE_CANCELED === $newState) {
                    $this->closedAt = new \DateTime();
                }

                break;

            case self::STATE_PENDING:
                if ( ! in_array($newState, array(self::STATE_RUNNING, self::STATE_CANCELED), true)) {
                    throw new InvalidStateTransitionException($this, $newState, array(self::STATE_RUNNING, self::STATE_CANCELED));
                }

                if ($newState === self::STATE_RUNNING) {
                    $this->startedAt = new \DateTime();
                    $this->checkedAt = new \DateTime();
                } else if ($newState === self::STATE_CANCELED) {
                    $this->closedAt = new \DateTime();
                }

                break;

            case self::STATE_RUNNING:
                if ( ! in_array($newState, array(self::STATE_FINISHED, self::STATE_FAILED, self::STATE_TERMINATED, self::STATE_INCOMPLETE))) {
                    throw new InvalidStateTransitionException($this, $newState, array(self::STATE_FINISHED, self::STATE_FAILED, self::STATE_TERMINATED, self::STATE_INCOMPLETE));
                }

                $this->closedAt = new \DateTime();

                break;

            case self::STATE_FINISHED:
            case self::STATE_FAILED:
            case self::STATE_TERMINATED:
            case self::STATE_INCOMPLETE:
                throw new InvalidStateTransitionException($this, $newState);

            default:
                throw new LogicException('The previous cases were exhaustive. Unknown state: '.$this->state);
        }

        $this->state = $newState;
    }
}
