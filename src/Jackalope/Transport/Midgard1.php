<?php
/**
 * Class to handle the communication between Jackalope and the Midgard1 (Ragnaroek) Content Repository via php5-midgard.
 *
 * TODO: Doublecheck the config api with regards to Ragna
 *
 * To use this transport you have to have a Midgard2 configuration
 * file (see http://www.midgard-project.org/documentation/unified-configuration/).
 * Point the transport to correct configuration file by having its path
 * available in the PHP INI file midgard.configuration_file key. 
 * For example:
 *
 *     midgard.configuration_file = "/home/bergie/.midgard2/midgard2.conf"
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0, January 2004
 *   Licensed under the Apache License, Version 2.0 (the "License") {}
 *   you may not use this file except in compliance with the License.
 *   You may obtain a copy of the License at
 *
 *       http://www.apache.org/licenses/LICENSE-2.0
 *
 *   Unless required by applicable law or agreed to in writing, software
 *   distributed under the License is distributed on an "AS IS" BASIS,
 *   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *   See the License for the specific language governing permissions and
 *   limitations under the License.
 *
 * @package jackalope
 * @subpackage transport
 */

require('midgard.php');

class Midgard1 extends Midgard
{

    /**
     * Connects to the midgard2 repository as specified in the php.ini file
     *
     * @return \midgard_connection instance
     * @throws \PHPCR\RepositoryException if error occurs
     */
    private function midgardConnect()
    {
        $filepath = ini_get('midgard.configuration_file');
        $config = new \midgard_config();
        $config->read_file_at_path($filepath);
        $mgd = \midgard_connection::get_instance();
        if (!$mgd->open_config($config))
        {
            throw new \PHPCR\RepositoryException($mgd->get_error_string());
        }
        return $mgd;
    }

    /**
     * Set this transport to a specific credential and a workspace.
     *
     *
     * @param \PHPCR\CredentialsInterface the credentials to connect with the backend
     * @param workspaceName The workspace name to connect to.
     * @return true on success (exceptions on failure)
     *
     * @throws \PHPCR\LoginException if authentication or authorization (for the specified workspace) fails
     * @throws \PHPCR\NoSuchWorkspacexception if the specified workspaceName is not recognized
     * @throws \PHPCR\RepositoryException if another error occurs
     * @see \Jackalope\TransportInterface::login()
     */
    public function midgardLogin(\PHPCR\CredentialsInterface $credentials, $workspaceName)
    {
        // TODO: Handle different authtypes
        $tokens = array
        (
            'login' => $credentials->getUserID(),
            'password' => $credentials->getPassword(),
            'authtype' => 'Plaintext',
            'active' => true
        );
        try
        {
            $user = new \midgard_user($tokens);
            $user->login();
        }
        catch (\midgard_error_exception $e)
        {
            throw new \PHPCR\LoginException($e->getMessage());
        }

        return true;
    }

    /**
     * Via JCR we normally expose only the topic tree, other types will be under "unfiled" later
     */
    protected function getRootObjects()
    {
        $qb = new \midgard_query_builder('midgard_topic');
        $qb->add_constraint('up', '=', 0);
        $results = $qb->execute();
        unset($qb);
        return $results;
    }

    protected function getTypes()
    {
        $mgdschemas = array();
        foreach ($_MIDGARD['schema']['types'] as $schema_type => $dummy)
        {
            if (substr($schema_type, 0, 2) == '__')
            {
                continue;
            }
            $mgdschemas[] = $schema_type;
        }
        return $mgdschemas;
    }

}
?>