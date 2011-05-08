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

class Midgard implements TransportInterface
{
    public function __construct()
    {
        $this->midgardConnect();
    }

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
        return $this->midgardLogin($credentials, $workspaceName);
    }

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

    protected function getRootObject($workspacename)
    {
        $rootnodes = $this->getRootObjects();
        if (empty($rootnodes))
        {
            throw new \PHPCR\NoSuchWorkspacexception('No workspaces defined');
        }
        return $rootnodes[0];
    }

    protected function getRootObjects()
    {
        // TODO: Use NotImplementedException or something ?
        throw new Exception('Must be implemented in a subclass');
    }

    protected function getTypes()
    {
        // TODO: Use NotImplementedException or something ?
        throw new Exception('Must be implemented in a subclass');
    }

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

                if ($link_class == $parent_class)
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
        $type = $reflector->get_midgard_type($property);
   
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
        throw new \PHPCR\ItemNotFoundException("Not found");
        // TODO: Implement with get_by_guid
    }    
}