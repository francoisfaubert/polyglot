<?php
namespace Polyglot\Plugin\Db;

use Polyglot\Plugin\TranslationEntity;
use Polyglot\Plugin\Db\Cache;
use Polyglot\Plugin\Db\Logger;

use WP_Post;
use Strata\Strata;

class Query {

    const WP_UNIQUE_KEY = "polyglot-plugin";
    const DB_VERSION = "0.1.0";

    protected $logger;
    private $cache;

    function __construct()
    {
        $this->logger = new Logger();
        $this->cache = new Cache();
    }

    public function createTranslationEntity($object)
    {
        $app = Strata::app();

        $entity = new TranslationEntity();
        $entity->obj_id = $object->ID;
        $entity->obj_kind = get_class($object);
        $entity->obj_type = $object->post_type;
        $entity->translation_locale = $app->i18n->getDefaultLocale()->getCode();
        $entity->translation_of = null;

        return $entity;
    }

    public function createTable()
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'polyglot';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $tableName (
            polyglot_ID mediumint(9) NOT NULL AUTO_INCREMENT,
            obj_kind varchar(10) NOT NULL,
            obj_type tinytext NOT NULL,
            obj_id mediumint(9) NOT NULL,
            translation_of mediumint(9) NOT NULL,
            translation_locale varchar(10),
            UNIQUE KEY polyglot_ID (polyglot_ID)
        ) $charset;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta($sql);

        $this->logger->log("Created or updated the Polyglot table.", "[Plugins::Polyglot]");

        add_option('polyglot_db_version', self::DB_VERSION);
    }

    public function unlinkTranslationFor($objectId, $objectKind)
    {
        global $wpdb;
        return $wpdb->delete($wpdb->prefix . 'polyglot', array(
            "obj_id" => $objectId,
            "obj_kind" => $objectKind
        ));
    }

    public function addPostTranslation($originalId, $originalType, $originalKind, $targetLocale)
    {
        global $wpdb;
        $originalPostTitle = get_the_title($originalId);
        $translationTitle = $originalPostTitle . " ($targetLocale)";

        if ($originalKind == "WP_Post") {
            $associationId = wp_insert_post(array(
                "post_title" => $translationTitle,
                "post_type" => $originalType
            ));
        } else {
            throw new Exception("We don't know how to duplicate $originalKind.");
        }

        if ((int)$associationId > 0) {

            $row = array(
                'obj_kind' => $originalKind,
                'obj_type' => $originalType,
                'obj_id'    => $associationId,
                'translation_of' => $originalId,
                'translation_locale' => $targetLocale
            );

            if ($wpdb->insert("{$wpdb->prefix}polyglot", $row)) {
                return $associationId;
            }

            throw new Exception("Could not save translation data.");
        }
        throw new Exception("Could not duplicate post.");
    }


    public function runCachableQuery($query, $queryType = "get_results")
    {
        global $wpdb;

        $this->logger->logQueryStart();

        if ($this->cache->has($query)) {
            $queryResults = $this->cache->get($query);
            $this->logger->logQueryCompletion($wpdb->last_query, true);
        } else {
            $queryResults = $wpdb->{$queryType}($query);
            $this->cache->set($query, $queryResults);
            $this->logger->logQueryCompletion($wpdb->last_query);
        }

        return $queryResults;
    }

    public function findAllTranlationsOfOriginal($object)
    {
        return $this->findAllTranlationsOfOriginalId($object->ID, get_class($object));
    }

    public function findAllTranlationsOfOriginalId($id, $kind = "WP_Post")
    {
        global $wpdb;
        return $this->rowsToEntities($this->runCachableQuery($wpdb->prepare("
            SELECT {$wpdb->prefix}polyglot.*,
                {$wpdb->prefix}posts.post_name
            FROM {$wpdb->prefix}polyglot
                LEFT JOIN {$wpdb->prefix}posts on {$wpdb->prefix}posts.ID = {$wpdb->prefix}polyglot.obj_id
            WHERE translation_of = %s
                AND obj_kind = %s
            ORDER BY polyglot_ID ASC",
            $id,
            $kind
        )));
    }


    public function findCachedPostById($id)
    {
        $this->logger->logQueryStart();

        $query = "get_post_$id";

        if ($this->cache->has($query)) {
            $queryResults = $this->cache->get($query);
            $this->logger->logQueryCompletion("Loaded post ID #$id", true);
        } else {
            $queryResults = get_post($id);
            $this->cache->set($query, $queryResults);
            $this->logger->logQueryCompletion("Loaded post ID #$id");
        }

        return $queryResults;
    }

    public function findCachedTaxonomyById($type, $id)
    {
        $this->logger->logQueryStart();

        $query = "get_taxonomy_$id\_$type";

        if ($this->cache->has($query)) {
            $queryResults = $this->cache->get($query);
            $this->logger->logQueryCompletion("Loaded taxonomy ID #$id", true);
        } else {
            $queryResults = get_the_terms($id, $type);
            $this->cache->set($query, $queryResults);
            $this->logger->logQueryCompletion("Loaded taxonomy ID #$id");
        }

        return $queryResults;
    }


    /**
     * Queries for the translation details of an object.
     * @param  mixed $object
     * @return TranslationEntity
     */
    public function findDetails($object)
    {
        global $wpdb;
        $result = $this->runCachableQuery($wpdb->prepare("
            SELECT *
            FROM {$wpdb->prefix}polyglot
                WHERE obj_id = %s
                AND obj_kind = %s
            ORDER BY polyglot_ID ASC
            LIMIT 1",
            $object->ID,
            get_class($object)
        ), "get_row");

        if (is_null($result) && $this->isInDefaultLocale($object)) {
            return $this->createTranslationEntity($object);
        }

        return new TranslationEntity($result);
    }


    /**
     * Fetches all the known translation details of an object's original parent.
     * Best for cases where you have a translation and need to build the list of
     * all locale possibilities.
     * @param  mixed $object
     * @return TranslationEntity
     */
    public function findOriginalTranslationDetails($object)
    {
        global $wpdb;
        return $this->rowsToEntities($this->runCachableQuery($wpdb->prepare("
            SELECT *
            FROM {$wpdb->prefix}polyglot
            WHERE translation_of =
                (
                    SELECT translation_of
                    FROM {$wpdb->prefix}polyglot
                        WHERE obj_id = %s
                        AND obj_kind = %s
                )",
            $object->ID,
            get_class($object)
        )));
    }

    /**
     * Find the
     * @param  WP_Post $translatedPost [description]
     * @return [type]                  [description]
     */
    public function findObjectLocale($translatedObj)
    {
        global $wpdb;
        $locale = $this->runCachableQuery($wpdb->prepare("
            SELECT translation_locale
            FROM {$wpdb->prefix}polyglot
                WHERE obj_id = %s
                AND obj_kind = %s
            ORDER BY polyglot_ID ASC",
            $translatedObj->ID,
            get_class($translatedObj)
        ), "get_var");


        if (is_null($locale)) {
            $app = Strata::app();
            return $app->i18n->getDefaultLocale()->getCode();
        }

        return $locale;
    }

    public function generateLocaleHomeUrlList()
    {
        $slugs = array();
        $defaultHomeId = $this->getDefaultHomepageId();

        // We only care if a page is on the front page
        if ($defaultHomeId > 0) {
            $translatedPages = $this->findAllTranlationsOfOriginalId($defaultHomeId);
            foreach ($translatedPages as $page) {
                $slugs[$page->translation_locale] = $page->post_name;
            }
        }

        return $slugs;
    }

    public function getDefaultHomepageId()
    {
        if ($this->hasHomePage()) {
            return (int)get_option('page_on_front');
        }

        return -1;
    }

    private function hasHomePage()
    {
        return get_option('show_on_front') == "page";
    }


    /**
     * Converts DB rows into translation entities
     * @param  array $rows
     * @return array
     */
    protected function rowsToEntities($rows)
    {
        $results = array();
        foreach ($rows as $row) {
            $results[] = new TranslationEntity($row);
        }
        return $results;
    }

    /**
     * @todo : This probably does not need to be here.
     */
    private function isInDefaultLocale($obj)
    {
        // The post had no parent version, however we must
        // confirm the queried post is not the default locale.
        // If it is, then it's normal that there are not parent posts found
        // because original posts aren't listed in the polyglot table.
        $app = Strata::app();
        return $this->findObjectLocale($obj) === $app->i18n->getDefaultLocale()->getCode();
    }

}
