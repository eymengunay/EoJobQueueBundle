<?php

namespace Eo\JobQueueBundle\Twig;

interface LinkGeneratorInterface
{
    function supports($document);
    function generate($document);
    function getLinkname($document);
}