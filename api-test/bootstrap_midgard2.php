<?php
require_once(dirname(__FILE__) . '/../src/Jackalope/autoloader.php');

function getRepository($config) {
    $transport = new \Jackalope\Transport\Midgard2();
    return new \Jackalope\Repository(null, null, $transport);
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
    require_once "midgard_importexport.php";
    return new midgard_importexport(__DIR__."/suite/fixtures/");
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