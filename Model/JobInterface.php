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

use Symfony\Component\HttpKernel\Exception\FlattenException;

/**
 * Eo\JobQueueBundle\Model\JobInterface
 *
 * @author Eymen Gunay <eymen@egunay.com>
 */
interface JobInterface
{
    public function getId();

    public function getState();

    public function isStartable();

    public function setState($newState);

    public function getCreatedAt();

    public function getClosedAt();

    public function getExecuteAfter();

    public function setExecuteAfter(\DateTime $executeAfter);

    public function getCommand();

    public function getArgs();

    public function isClosedNonSuccessful();

    public function getDependencies();

    public function hasDependency(JobInterface $job);

    public function addDependency(JobInterface $job);

    public function getRuntime();

    public function setRuntime($time);

    public function getMemoryUsage();

    public function getMemoryUsageReal();

    public function addOutput($output);

    public function addErrorOutput($output);

    public function setOutput($output);

    public function setErrorOutput($output);

    public function getOutput();

    public function getErrorOutput();

    public function setExitCode($code);

    public function getExitCode();

    public function setMaxRuntime($time);

    public function getMaxRuntime();

    public function getStartedAt();

    public function getMaxRetries();

    public function setMaxRetries($tries);

    public function isRetryAllowed();

    public function getOriginalJob();

    public function setOriginalJob(JobInterface $job);

    public function addRetryJob(JobInterface $job);

    public function getRetryJobs();

    public function isRetryJob();

    public function checked();

    public function getCheckedAt();

    public function setStackTrace(FlattenException $ex);

    public function getStackTrace();

    public function isNew();

    public function isPending();

    public function isCanceled();

    public function isRunning();

    public function isTerminated();

    public function isFailed();

    public function isFinished();

    public function isIncomplete();

    public function __toString();
}
