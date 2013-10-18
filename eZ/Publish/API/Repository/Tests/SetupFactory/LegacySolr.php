<?php
/**
 * File containing the Test Setup Factory base class
 *
 * @copyright Copyright (C) 1999-2013 eZ Systems AS. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2
 * @version //autogentag//
 */

namespace eZ\Publish\API\Repository\Tests\SetupFactory;

use eZ\Publish\Core\Persistence\Solr;
use eZ\Publish\Core\Persistence\Solr\Content\Search;
use eZ\Publish\Core\Persistence\Solr\Content\Search\CriterionVisitor;
use eZ\Publish\Core\Persistence\Solr\Content\Search\FacetBuilderVisitor;
use eZ\Publish\Core\Persistence\Solr\Content\Search\FieldNameGenerator;
use eZ\Publish\Core\Persistence\Solr\Content\Search\FieldRegistry;
use eZ\Publish\Core\Persistence\Solr\Content\Search\FieldValueMapper;
use eZ\Publish\Core\Persistence\Solr\Content\Search\SortClauseVisitor;
use eZ\Publish\Core\Persistence\Solr\Slot;
use eZ\Publish\Core\FieldType;
use eZ\Publish\Core\SignalSlot\Repository as SignalSlotRepository;
use eZ\Publish\Core\SignalSlot\SignalDispatcher\DefaultSignalDispatcher;
use eZ\Publish\Core\SignalSlot\SlotFactory\GeneralSlotFactory;
use eZ\Publish\Core\Persistence\Legacy\Handler as LegacyPersistenceHandler;
use eZ\Publish\Core\Persistence\Cache\Handler as CachePersistenceHandler;
use eZ\Publish\SPI\Persistence\Content\Search\Handler as SearchHandler;

/**
 * A Test Factory is used to setup the infrastructure for a tests, based on a
 * specific repository implementation to test.
 */
class LegacySolr extends Legacy
{
    /**
     * Returns a configured repository for testing.
     *
     * @param bool $initializeFromScratch
     * @return \eZ\Publish\API\Repository\Repository
     */
    public function getRepository( $initializeFromScratch = true )
    {
        // Load repository fists so all initialize steps are done
        $repository = parent::getRepository( $initializeFromScratch );

        // @TODO @HACK: This is a hack to inject a different search handler -- is
        // there a well supported way to do this? I don't think so.
        $persistenceHandler = $this->getServiceContainer()->get( 'persistence_handler' );
        $legacyPersistenceHandler = $this->getServiceContainer()->get( 'persistence_handler_legacy' );
        $searchProperty = new \ReflectionProperty( $legacyPersistenceHandler, 'searchHandler' );
        $searchProperty->setAccessible( true );
        $searchProperty->setValue(
            $legacyPersistenceHandler,
            $searchHandler = $this->getSearchHandler( $persistenceHandler )
        );

        if ( $initializeFromScratch )
        {
            $this->indexAll( $legacyPersistenceHandler, $persistenceHandler, $searchHandler );
        }

        $repository = new SignalSlotRepository(
            $repository,
            new DefaultSignalDispatcher(
                new GeneralSlotFactory(
                    array(
                        // Attention: we are passing the NON SignalSlotted repository here because it is still under creation
                        // this might be an issue and might require a dedicated setRepository() method.
                        "solr-publish-version" => new Slot\PublishVersion( $repository, $persistenceHandler ),
                        "solr-copy-content" => new Slot\CopyContent( $repository, $persistenceHandler ),
                        "solr-delete-content" => new Slot\DeleteContent( $repository, $persistenceHandler ),
                        "solr-delete-version" => new Slot\DeleteVersion( $repository, $persistenceHandler ),
                        "solr-delete-location" => new Slot\DeleteLocation( $repository, $persistenceHandler ),
                        "solr-create-user" => new Slot\CreateUser( $repository, $persistenceHandler ),
                        "solr-create-user-group" => new Slot\CreateUserGroup( $repository, $persistenceHandler ),
                        "solr-move-user-group" => new Slot\MoveUserGroup( $repository, $persistenceHandler ),
                        "solr-copy-subtree" => new Slot\CopySubtree( $repository, $persistenceHandler ),
                        "solr-move-subtree" => new Slot\MoveSubtree( $repository, $persistenceHandler ),
                        "solr-trash" => new Slot\Trash( $repository, $persistenceHandler ),
                        "solr-trash-recover" => new Slot\Recover( $repository, $persistenceHandler ),
                    )
                ),
                array(
                    "eZ\\Publish\\Core\\SignalSlot\\Signal\\ContentService\\PublishVersionSignal" => array( "solr-publish-version" ),
                    "eZ\\Publish\\Core\\SignalSlot\\Signal\\ContentService\\DeleteContentSignal" => array( "solr-delete-content" ),
                    "eZ\\Publish\\Core\\SignalSlot\\Signal\\ContentService\\DeleteVersionSignal" => array( "solr-delete-version" ),
                    "eZ\\Publish\\Core\\SignalSlot\\Signal\\ContentService\\CopyContentSignal" => array( "solr-copy-content" ),
                    "eZ\\Publish\\Core\\SignalSlot\\Signal\\LocationService\\DeleteLocationSignal" => array( "solr-delete-location" ),
                    "eZ\\Publish\\Core\\SignalSlot\\Signal\\LocationService\\CopySubtreeSignal" => array( "solr-copy-subtree" ),
                    "eZ\\Publish\\Core\\SignalSlot\\Signal\\LocationService\\MoveSubtreeSignal" => array( "solr-move-subtree" ),
                    "eZ\\Publish\\Core\\SignalSlot\\Signal\\TrashService\\TrashSignal" => array( "solr-trash" ),
                    "eZ\\Publish\\Core\\SignalSlot\\Signal\\TrashService\\RecoverSignal" => array( "solr-trash-recover" ),
                    "eZ\\Publish\\Core\\SignalSlot\\Signal\\UserService\\CreateUserSignal" => array( "solr-create-user" ),
                    "eZ\\Publish\\Core\\SignalSlot\\Signal\\UserService\\CreateUserGroupSignal" => array( "solr-create-user-group" ),
                    "eZ\\Publish\\Core\\SignalSlot\\Signal\\UserService\\MoveUserGroupSignal" => array( "solr-move-user-group" ),
                )
            )
        );

        return $repository;
    }

    /**
     * @param CachePersistenceHandler $persistenceHandler
     * @return Search\Handler
     */
    protected function getSearchHandler( CachePersistenceHandler $persistenceHandler )
    {
        $nameGenerator = new FieldNameGenerator();
        $fieldRegistry = new FieldRegistry(
            array(
                'ezstring'              => new FieldType\TextLine\SearchField(),
                'ezprice'               => new FieldType\Price\SearchField(),
                // @todo: These two need proper custom search field definitions
                'eztext'                => new FieldType\TextLine\SearchField(),
                'ezxmltext'             => new FieldType\TextLine\SearchField(),
                // @todo: Define proper types for these:
                'ezcountry'             => new FieldType\Country\SearchField(),
                'ezfloat'               => new FieldType\Unindexed(),
                'ezinteger'             => new FieldType\Unindexed(),
                'ezuser'                => new FieldType\Unindexed(),
                'ezimage'               => new FieldType\Unindexed(),
                'ezboolean'             => new FieldType\Unindexed(),
                'ezkeyword'             => new FieldType\Unindexed(),
                'ezdate'                => new FieldType\Unindexed(),
                'eztime'                => new FieldType\Unindexed(),
                'ezdatetime'            => new FieldType\Unindexed(),
                'ezinisetting'          => new FieldType\Unindexed(),
                'ezpackage'             => new FieldType\Unindexed(),
                'ezurl'                 => new FieldType\Unindexed(),
                'ezobjectrelation'      => new FieldType\Unindexed(),
                'ezmultioption'         => new FieldType\Unindexed(),
                'ezauthor'              => new FieldType\Unindexed(),
                'ezsrrating'            => new FieldType\Unindexed(),
                'ezselection'           => new FieldType\Unindexed(),
                'ezsubtreesubscription' => new FieldType\Unindexed(),
                'ezobjectrelationlist'  => new FieldType\Unindexed(),
                'ezemail'               => new FieldType\Unindexed(),
                'ezoption'              => new FieldType\Unindexed(),
                'ezgmaplocation'        => new FieldType\Unindexed(),
                'ezbinaryfile'          => new FieldType\Unindexed(),
                'ezmedia'               => new FieldType\Unindexed(),
                'ezpage'                => new FieldType\Unindexed(),
                'ezcomcomments'         => new FieldType\Unindexed(),
            )
        );

        return new Search\Handler(
            new Search\Gateway\Native(
                new Search\Gateway\HttpClient\Stream( getenv( "solrServer" ) ),
                new CriterionVisitor\Aggregate(
                    array(
                        new CriterionVisitor\ContentIdIn(),
                        new CriterionVisitor\LogicalAnd(),
                        new CriterionVisitor\LogicalOr(),
                        new CriterionVisitor\LogicalNot(),
                        new CriterionVisitor\SubtreeIn(),
                        new CriterionVisitor\ContentTypeIdIn(),
                        new CriterionVisitor\ContentTypeGroupIdIn(),
                        new CriterionVisitor\LocationIdIn(),
                        new CriterionVisitor\ParentLocationIdIn(),
                        new CriterionVisitor\SectionIn(),
                        new CriterionVisitor\RemoteIdIn(),
                        new CriterionVisitor\LanguageCodeIn(),
                        new CriterionVisitor\ObjectStateIdIn(),
                        new CriterionVisitor\LocationRemoteIdIn(),
                        new CriterionVisitor\DateMetadata\ModifiedIn(),
                        new CriterionVisitor\DateMetadata\PublishedIn(),
                        new CriterionVisitor\DateMetadata\ModifiedBetween(),
                        new CriterionVisitor\DateMetadata\PublishedBetween(),
                        new CriterionVisitor\FullText(),
                        new CriterionVisitor\Field\FieldIn(
                            $fieldRegistry,
                            $persistenceHandler->contentTypeHandler(),
                            $nameGenerator
                        ),
                        new CriterionVisitor\Field\FieldRange(
                            $fieldRegistry,
                            $persistenceHandler->contentTypeHandler(),
                            $nameGenerator
                        ),
                    )
                ),
                new SortClauseVisitor\Aggregate(
                    array(
                        new SortClauseVisitor\ContentId(),
                        new SortClauseVisitor\ContentName(),
                        new SortClauseVisitor\LocationPathString(),
                        new SortClauseVisitor\LocationDepth(),
                        new SortClauseVisitor\LocationPriority(),
                        new SortClauseVisitor\SectionIdentifier(),
                        new SortClauseVisitor\SectionName(),
                        new SortClauseVisitor\DatePublished(),
                    )
                ),
                new FacetBuilderVisitor\Aggregate(
                    array(
                        new FacetBuilderVisitor\ContentType(),
                        new FacetBuilderVisitor\Section(),
                        new FacetBuilderVisitor\User(),
                    )
                ),
                new FieldValueMapper\Aggregate(
                    array(
                        new FieldValueMapper\IdentifierMapper(),
                        new FieldValueMapper\MultipleIdentifierMapper(),
                        new FieldValueMapper\StringMapper(),
                        new FieldValueMapper\MultipleStringMapper(),
                        new FieldValueMapper\IntegerMapper(),
                        new FieldValueMapper\DateMapper(),
                        new FieldValueMapper\PriceMapper(),
                    )
                ),
                $persistenceHandler->contentHandler(),
                $nameGenerator
            ),
            $fieldRegistry,
            $persistenceHandler->locationHandler(),
            $persistenceHandler->contentTypeHandler(),
            $persistenceHandler->objectStateHandler(),
            $persistenceHandler->sectionHandler()
        );
    }

    /**
     * @param LegacyPersistenceHandler $legacyPersistenceHandler
     * @param CachePersistenceHandler $cachePersistenceHandler
     * @param SearchHandler $searchHandler
     */
    protected function indexAll( LegacyPersistenceHandler $legacyPersistenceHandler, CachePersistenceHandler $cachePersistenceHandler, SearchHandler $searchHandler )
    {
        // @todo: Is there a nicer way to get access to all content objects? We
        // require this to run a full index here.
        $dbHandlerProperty = new \ReflectionProperty( $legacyPersistenceHandler, 'dbHandler' );
        $dbHandlerProperty->setAccessible( true );
        $db = $dbHandlerProperty->getValue( $legacyPersistenceHandler );

        $query = $db->createSelectQuery()
            ->select( 'id', 'current_version' )
            ->from( 'ezcontentobject' );

        $stmt = $query->prepare();
        $stmt->execute();

        $searchHandler->purgeIndex();

        /**
         * Reflect lots of things in Search Handler to be able to index several documents at the same time and
         * to make sure commit is done at the end
         */
        $mapContentFunction = new \ReflectionMethod( $searchHandler, 'mapContent' );
        $mapContentFunction->setAccessible( true );

        $searchGatewayProperty = new \ReflectionProperty( $searchHandler, 'gateway' );
        $searchGatewayProperty->setAccessible( true );
        /** @var \eZ\Publish\Core\Persistence\Solr\Content\Search\Gateway $searchGateway */
        $searchGateway = $searchGatewayProperty->getValue( $searchHandler );

        $createUpdateFunction = new \ReflectionMethod( $searchGateway, 'createUpdate' );
        $createUpdateFunction->setAccessible( true );

        $clientProperty = new \ReflectionProperty( $searchGateway, 'client' );
        $clientProperty->setAccessible( true );
        $httpClient = $clientProperty->getValue( $searchGateway );

        $i = 0;
        $xml = new \XmlWriter();
        $xml->openMemory();
        $xml->startElement( 'add' );
        while ( $row = $stmt->fetch( \PDO::FETCH_ASSOC ) )
        {
            // send on every 10 document to not have to large documents, but do it first to get commit at the end
            if ( $i !== 0 && ( $i % 20 ) === 0 )
            {
                $xml->endElement();
                $this->solrUpdate( $httpClient, $xml->outputMemory( true ), false );
                $xml = new \XmlWriter();
                $xml->openMemory();
                $xml->startElement( 'add' );
            }

            $content = $cachePersistenceHandler->contentHandler()->load( $row['id'], $row['current_version'] );
            $fields = $mapContentFunction->invoke( $searchHandler, $content );
            $createUpdateFunction->invoke( $searchGateway, $fields, $xml );
            $i++;
        }
        // Do final update and commit
        $xml->endElement();
        $this->solrUpdate( $httpClient, $xml->outputMemory( true ), true );
    }

    /**
     * @param Search\Gateway\HttpClient $client
     * @param $solrDoc
     * @param bool $commit
     * @throws \RuntimeException
     */
    protected function solrUpdate( Search\Gateway\HttpClient $client, $solrDoc, $commit = true )
    {
        $result = $client->request(
            'POST',
            '/solr/update?' . ( $commit ? 'commit=true&' : '' ) . 'wt=json',
            new Search\Gateway\Message(
                array(
                    'Content-Type: text/xml',
                ),
                $solrDoc
            )
        );

        if ( $result->headers["status"] !== 200 )
        {
            throw new \RuntimeException( "Wrong HTTP status received from Solr: " . $result->headers["status"] );
        }
    }
}
