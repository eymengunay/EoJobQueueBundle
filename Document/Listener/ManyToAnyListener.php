<?php

namespace Eo\JobQueueBundle\Document\Listener;

/**
 * Provides many-to-any association support for jobs.
 *
 * This listener only implements the minimal support for this feature. For
 * example, currently we do not support any modification of a collection after
 * its initial creation.
 *
 * @see http://docs.jboss.org/hibernate/orm/4.1/javadocs/org/hibernate/annotations/ManyToAny.html
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class ManyToAnyListener
{
    private $registry;
    private $ref;

    public function __construct(\Symfony\Bridge\Doctrine\RegistryInterface $registry)
    {
        $this->registry = $registry;
        $this->ref = new \ReflectionProperty('Eo\JobQueueBundle\Document\Job', 'relatedDocuments');
        $this->ref->setAccessible(true);
    }

    public function postLoad(\Doctrine\Common\EventArgs\LifecycleEventArgs $event)
    {
        $document = $event->getDocument();
        if ( ! $document instanceof \Eo\JobQueueBundle\Document\Job) {
            return;
        }

        $this->ref->setValue($document, new PersistentRelatedDocumentsCollection($this->registry, $document));
    }

    public function postPersist(\Doctrine\Common\EventArgs\LifecycleEventArgs $event)
    {
        $document = $event->getDocument();
        if ( ! $document instanceof \Eo\JobQueueBundle\Document\Job) {
            return;
        }

        $con = $event->getDocumentManager()->getConnection();
        foreach ($this->ref->getValue($document) as $relatedDocument) {
            $relClass = \Doctrine\Common\Util\ClassUtils::getClass($relatedDocument);
            $relId = $this->registry->getManagerForClass($relClass)->getMetadataFactory()->getMetadataFor($relClass)->getIdentifierValues($relatedDocument);
            asort($relId);

            if ( ! $relId) {
                throw new \RuntimeException('The identifier for the related document "'.$relClass.'" was empty.');
            }

            $con->executeUpdate("INSERT INTO eo_job_related_documents (job_id, related_class, related_id) VALUES (:jobId, :relClass, :relId)", array(
                'jobId' => $document->getId(),
                'relClass' => $relClass,
                'relId' => json_encode($relId),
            ));
        }
    }
}