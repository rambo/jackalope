<?php
require_once(dirname(__FILE__) . '/../src/Jackalope/autoloader.php');

function getRepository($config) {
    $mgd = getMidgardConnection();

    $transport = new \Jackalope\Transport\Midgard2($mgd);
    return new \Jackalope\Repository(null, null, $transport);
}

function prepareMidgardTestDir($dir)
{
    if (!file_exists("/tmp/JackalopeMidgard2/{$dir}"))
    {
        mkdir("/tmp/JackalopeMidgard2/{$dir}", 0777, true);
    }
}

function getMidgardConnection() {
    // Open connection
    $midgard = \midgard_connection::get_instance();
    if ($midgard->is_connected())
    {
        // Already connected
        return $midgard;
    }

    prepareMidgardTestDir('share');
    prepareMidgardTestDir('blobs');
    prepareMidgardTestDir('var');
    prepareMidgardTestDir('cache');

    exec("cp -r Midgard2/share/* /tmp/JackalopeMidgard2/share");
    
    $config = new \midgard_config();
    $config->read_file_at_path(dirname(__FILE__) . "/Midgard2/midgard2.conf");
    if (!$midgard->open_config($config))
    {
        throw new Exception('Could not open Midgard connection to test database: ' . $midgard->get_error_string());
    }

    prepareMidgardStorage();

    return $midgard;
}

function prepareMidgardStorage()
{
    midgard_storage::create_base_storage();

    // And update as necessary
    $re = new ReflectionExtension('midgard2');
    $classes = $re->getClasses();
    foreach ($classes as $refclass)
    {
        $parent_class = $refclass->getParentClass();
        if (!$parent_class)
        {
            continue;
        }
        if ($parent_class->getName() != 'midgard_object')
        {
            continue;
        }

        $type = $refclass->getName();            
        if (midgard_storage::class_storage_exists($type))
        {
            continue;
        }

        if (!midgard_storage::create_class_storage($type))
        {
            throw new Exception('Could not create ' . $type . ' tables in test database');
        }
    }
}

function getSimpleCredentials($user, $password) {
    return new \PHPCR\SimpleCredentials($user, $password);
}

function getJCRSession($config, $credentials = null)
{
    $repository = getRepository($config);
    $credentials = getSimpleCredentials('admin', 'password');
    return $repository->login($credentials, $config['workspace']);
}

function getFixtureLoader($config)
{
    require_once "Midgard2/Midgard2ImportExport.php";
    return new Midgard2ImportExport(__DIR__."/suite/fixtures/");
}

define('SPEC_VERSION_DESC', 'jcr.specification.version');
define('SPEC_NAME_DESC', 'jcr.specification.name');
define('REP_VENDOR_DESC', 'jcr.repository.vendor');
define('REP_VENDOR_URL_DESC', 'jcr.repository.vendor.url');
define('REP_NAME_DESC', 'jcr.repository.name');
define('REP_VERSION_DESC', 'jcr.repository.version');
define('LEVEL_1_SUPPORTED', 'level.1.supported');
define('LEVEL_2_SUPPORTED', 'level.2.supported');
define('OPTION_TRANSACTIONS_SUPPORTED', 'option.transactions.supported');
define('OPTION_VERSIONING_SUPPORTED', 'option.versioning.supported');
define('OPTION_OBSERVATION_SUPPORTED', 'option.observation.supported');
define('OPTION_LOCKING_SUPPORTED', 'option.locking.supported');
define('OPTION_QUERY_SQL_SUPPORTED', 'option.query.sql.supported');
define('QUERY_XPATH_POS_INDEX', 'query.xpath.pos.index');
define('QUERY_XPATH_DOC_ORDER', 'query.xpath.doc.order');