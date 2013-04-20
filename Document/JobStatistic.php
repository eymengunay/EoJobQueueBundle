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

namespace JMS\JobQueueBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

use Doctrine\Common\Collections\ArrayCollection;
use JMS\JobQueueBundle\Exception\InvalidStateTransitionException;
use JMS\JobQueueBundle\Exception\LogicException;
use JMS\JobQueueBundle\Model\JobInterface;
use Symfony\Component\HttpKernel\Exception\FlattenException;

/**
 * @ODM\Document()
 * @ODM\ChangeTrackingPolicy("DEFERRED_EXPLICIT")
 *
 * @author Eymen Gunay <eymen@egunay.com>
 */
class JobStatistic
{
    /**
     * @ODM\Id(strategy="auto")
     */
    private $id;

    /**
     * @ODM\Field(type="int")
     * @ODM\Index
     */
    private $jobId;

    /**
     * @ODM\Field(type="string")
     * @ODM\Index
     */
    private $characteristic;

    /**
     * @ODM\Field(type="date", nullable = true)
     * @ODM\Index
     */
    private $createdAt;

    /**
     * @ODM\Float
     */
    private $charValue;

    public function getId()
    {
        return $this->id;
    }

    public function setJobId($jobId)
    {
        $this->jobId = $jobId;
        return $this;
    }

    public function getJobId()
    {
        return $this->jobId;
    }

    public function setCharacteristic($characteristic)
    {
        $this->characteristic = $characteristic;
        return $this;
    }

    public function getCharacteristic()
    {
        return $this->characteristic;
    }

    public function setCreatedAt($createdAt)
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    public function setCharValue($charValue)
    {
        $this->charValue = $charValue;
        return $this;
    }

    public function getCharValue()
    {
        return $this->charValue;
    }
}
