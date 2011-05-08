<?php
/**
 * Class to handle the communication between Jackalope and the Midgard2 Content Repository via php5-midgard2.
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

class Midgard2 implements TransportInterface
{
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

    private function getRootObject($workspacename)
    {
        $rootnodes = $this->getRootObjects();
        if (empty($rootnodes))
        {
            throw new \PHPCR\NoSuchWorkspacexception('No workspaces defined');
        }
        return $rootnodes[0];
    }
    
    private function getRootObjects()
    {
        // TODO: Support all MgdSchema rootlevel types
        $q = new \midgard_query_select(new \midgard_query_storage('midgardmvc_core_node'));
        $q->set_constraint(new \midgard_query_constraint(new \midgard_query_property('up'), '=', new \midgard_query_value(0)));
        $q->execute();
        return $q->list_objects();
    }

    private function getTypes()
    {
        $mgdschemas = array();
        $re = new \ReflectionExtension('midgard2');
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
            $mgdschemas[$include_views][] = $refclass->getName();
        }
        return $mgdschemas;
    }

    private function getChildTypes($midgard_class)
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

    private function getChildren(\midgard_object $object)
    {
        $children = array();
        $childTypes = $this->getChildTypes(get_class($object));
        foreach ($childTypes as $childType)
        {
            $children = array_merge($children, $object->list_children($childType));
        }
        return $children;        
    }

    private function getChild(\midgard_object $object, $name)
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
    
    private function getObjectByPath($path)
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
        $node = array();
        $object = $this->getObjectByPath($path);
        if (!$object)
        {
            throw new \PHPCR\ItemNotFoundException("No object at {$path}");
        }

        $props = get_object_vars($object);
        foreach ($props as $property => $value)
        {
            $node[$property] = $value;
        }
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