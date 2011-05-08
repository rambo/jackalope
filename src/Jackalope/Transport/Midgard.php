<?php
/**
 * Class to handle the communication between Jackalope and the Midgard2 Content Repository via php5-midgard2.
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

namespace Jackalope\Transport;

use PHPCR\PropertyType;
use Jackalope\TransportInterface;
use PHPCR\RepositoryException;
use Jackalope\Helper;

abstract class Midgard implements TransportInterface
{
    public function __construct()
    {
        $this->midgardConnect();
    }
    
    abstract function midgardConnect();

    /**
     * Get the repository descriptors from Midgard2
     * This happens without login or accessing a specific workspace.
     *
     * @return Array with name => Value for the descriptors
     * @throws \PHPCR\RepositoryException if error occurs
     */
    public function getRepositoryDescriptors()
    {
        return array();
    }

    /**
     * Returns the workspace names that can be used when logging in.
     *
     * @return array List of workspaces that can be specified on login
     */
    public function getAccessibleWorkspaceNames()
    {
        return array('tests');
    }

    /**
     * Set this transport to a specific credential and a workspace. proxies to midgardLogin method that subclasses will implement
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
    public function login(\PHPCR\CredentialsInterface $credentials, $workspaceName)
    {
        if (!in_array($workspaceName, $this->getAccessibleWorkspaceNames()))
        {
            throw new \PHPCR\NoSuchWorkspaceException("Workspace {$workspaceName} not defined");
        }  
        return $this->midgardLogin($credentials, $workspaceName);
    }
    
    abstract function midgardLogin(\PHPCR\CredentialsInterface $credentials, $workspaceName);

    /**
     * Get the registered namespaces mappings from Midgard2.
     * By default this includes the 'mgd' namespace. For
     * registering others, please see the "MgdSchemaRDF" 
     * specification.
     *
     * @return array Associative array of prefix => uri
     *
     * @throws \PHPCR\RepositoryException if not logged in
     */
    public function getNamespaces()
    {
        return array
        (
            'mgd' => 'http://www.midgard-project.org/repligard/1.4'
        );
    }

    protected function getRootObject($workspacename = '')
    {
        $rootnodes = $this->getRootObjects();
        if (empty($rootnodes))
        {
            throw new \PHPCR\NoSuchWorkspacexception('No workspaces defined');
        }
        return $rootnodes[0];
    }

    abstract protected function getRootObjects();

    abstract protected function getTypes();

    protected function getChildTypes($midgard_class)
    {
        $mgdschemas = $this->getTypes();
        $child_types = array();
        foreach ($mgdschemas as $mgdschema)
        {
            if (   $mgdschema == 'midgard_attachment'
                || $mgdschema == 'midgard_parameter')
            {
                continue;
            }

            $link_properties = array
            (
                'parent' => \midgard_object_class::get_property_parent($mgdschema),
                'up' => \midgard_object_class::get_property_up($mgdschema),
            );

            $ref = new \midgard_reflection_property($mgdschema);
            foreach ($link_properties as $type => $property)
            {
                $link_class = $ref->get_link_name($property);
                if (   empty($link_class)
                    && $ref->get_midgard_type($property) === MGD_TYPE_GUID)
                {
                    $child_types[] = $mgdschema;
                    continue;
                }

                if ($link_class == $midgard_class)
                {
                    $child_types[] = $mgdschema;
                }
            }
        }
        return $child_types;
    }

    protected function getChildren(\midgard_object $object)
    {
        $children = array();
        $childTypes = $this->getChildTypes(get_class($object));
        foreach ($childTypes as $childType)
        {
            $children = array_merge($children, $object->list_children($childType));
        }
        return $children;        
    }

    protected function getChild(\midgard_object $object, $name)
    {
        $children = $this->getChildren($object);
        foreach ($children as $child)
        {
            // TODO: Better checks via midgard reflection ?
            if (!\property_exists($child, 'name'))
            {
                continue;
            }
            if ($child->name == $name)
            {
                return $child;
            }
        }
        return false;
    }
    
    protected function getObjectByPath($path)
    {
        static $objects_by_path;
        $object = $this->getRootObject();
        if ($path == '/')
        {
            return $object;
        }

        $path_parts = explode('/', $path);
        foreach ($path_parts as $part)
        {
            $object = $this->getChild($object, $part);
            if (!$object)
            {
                return false;
            }
        }
        return $object;
    }

    protected function getPropertyType($class, $property)
    {
        static $reflectors = array();
        if (!isset($reflectors[$class]))
        {
            $reflectors[$class] = new \midgard_reflection_property($class);
        }
        $type = $reflectors[$class]->get_midgard_type($property);
   
        if ($type == MGD_TYPE_STRING)
        {
            if ($property == 'name')
            {
                return \PHPCR\PropertyType::PATH;
            }
            return \PHPCR\PropertyType::STRING;
        }

        return \PHPCR\PropertyType::UNDEFINED;
    }

    /**
     * Get the node that is stored at an absolute path
     *
     * @param string $path Absolute path to identify a special item.
     * @return array for the node
     *
     * @throws \PHPCR\RepositoryException if not logged in
     */
    public function getNode($path)
    {
        $node = new \StdClass();
        $object = $this->getObjectByPath($path);
        if (!$object)
        {
            throw new \PHPCR\ItemNotFoundException("No object at {$path}");
        }

        $props = get_object_vars($object);
        foreach ($props as $property => $value)
        {
            $node->{$property} = $value;
            $node->{':' . $property} = $this->getPropertyType(get_class($object), $property);
        }

        $children = $this->getChildren($object);
        foreach ($children as $child)
        {
            if (!$child->name)
            {
                continue;
            }
            $node->{$child->name} = new \StdClass();
        }

        return $node;
    }

    public function getProperty($path)
    {
        throw new \PHPCR\ItemNotFoundException("Not found");
    }

    /**
     * Get the node path from a JCR uuid
     *
     * @param string $uuid the id in JCR format
     * @return string Absolute path to the node
     *
     * @throws \PHPCR\ItemNotFoundException if the backend does not know the uuid
     * @throws \PHPCR\RepositoryException if not logged in
     */
    public function getNodePathForIdentifier($uuid)
    {
        // TODO: Implement with get_by_guid
        try
        {
            $object = midgard_object_class::get_object_by_guid($guid);
        }
        catch (\midgard_error_exception $e)
        {
            throw new \PHPCR\ItemNotFoundException($e->getMessage());
        }
        return $this->getPathForMidgardObject($object);
    }

    /**
     * Resolve objects path.
     */
    function getPathForMidgardObject(&$object)
    {
        $parts = array();
        $parts[] = $object->name;
        while (true)
        {
            try
            {
                $parent = $object->get_parent();
                if (!$parent)
                {
                    break;
                }
            }
            catch (\midgard_error_exception $e)
            {
                break;
            }
            $parts[] = $parent->name;
        }
        $ret = '/' . implode('/', array_reverse($parts));
        unset($parts);
        return $ret;
    }

    public function getBinaryStream($path)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException("Not supported");
    }

    public function copyNode($srcAbsPath, $dstAbsPath, $srcWorkspace = null)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException("Not supported");
    }

    public function cloneFrom($srcWorkspace, $srcAbsPath, $destAbsPath, $removeExisting)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException("Not supported");
    }

    public function moveNode($srcAbsPath, $dstAbsPath)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException("Not supported");
    }

    public function deleteNode($path)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException("Not supported");
    }

    public function deleteProperty($path)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException("Not supported");
    }

    public function storeNode(\PHPCR\NodeInterface $node)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException("Not supported");
    }

    public function storeProperty(\PHPCR\PropertyInterface $property)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException("Not supported");
    }

    /**
     * Pass the node type manager into the transport to be used for validation and such.
     *
     * @param NodeTypeManager $nodeTypeManager
     * @return void
     */
    public function setNodeTypeManager($nodeTypeManager)
    {
    }

    public function getNodeTypes($nodeTypes = array())
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException("Not supported");
    }

    public function registerNodeTypesCnd($cnd, $allowUpdate)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException("Not supported");
    }

    public function registerNodeTypes($types, $allowUpdate)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException("Not supported");
    }

    public function query(\PHPCR\Query\QueryInterface $query)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException("Not supported");
    }

    public function checkinItem($path)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException("Not supported");
    }

    public function checkoutItem($path)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException("Not supported");
    }

    public function restoreItem($removeExisting, $versionPath, $path)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException("Not supported");
    }

    public function getVersionHistory($path)
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException("Not supported");
    }
}