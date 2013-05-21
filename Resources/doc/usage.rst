Usage
-----

Creating Jobs
=============
Creating jobs is super simple, you just need to persist an instance of ``Job``:

.. code-block :: php

    <?php

    $job = new Job('my-symfony2:command', array('some-args', 'or', '--options="foo"'));
    $em->persist($job);
    $em->flush($job);

Adding Dependencies Between Jobs
================================
If you want to have a job run after another job finishes, you can also achieve this
quite easily:

.. code-block :: php

    <?php

    $job = new Job('a');
    $dependentJob = new Job('b');
    $dependentJob->addJobDependency($job);
    $em->persist($job);
    $em->persist($dependentJob);
    $em->flush();

Adding Related Documents to Jobs
===============================
If you want to link a job to another document, for example to find the job more
easily, the job provides a special many-to-any association:

.. code-block :: php

    <?php

    $job = new Job('a');
    $job->addRelatedDocument($anyDocument);
    $em->persist($job);
    $em->flush();

    $em->getRepository('EoJobQueueBundle:Job')->findJobForRelatedDocument('a', $anyDocument);

Schedule a Jobs
===============
If you want to schedule a job :

.. code-block :: php

    <?php

    $job = new Job('a');
    $date = new DateTime();
    $date->add(new DateInterval('PT30M'));
    $job->setExecuteAfter($date);
    $em->persist($job);
    $em->flush();
