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