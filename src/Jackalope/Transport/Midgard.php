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
    public function __construct(\midgard_connection $connection = null)
    {
        if (!$connection)
        {
            $this->midgardConnect();
        }
    }
    
    abstract protected function midgardConnect();

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
        $rootnodes = $this->getRootObjects($workspacename);
        if (empty($rootnodes))
        {
            throw new \PHPCR\NoSuchWorkspacexception('No workspaces defined');
        }
        return $rootnodes[0];
    }

    abstract protected function getRootObjects($workspacename);

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

            $ref =& $this->getMgdschemaReflector($mgdschema);
            foreach ($link_properties as $type => $property)
            {
                $link_class = $ref->get_link_name($property);
                if (   empty($link_class)
                    // TODO: What is this ? Why only GUID ?
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
            $name_property = $this->getNameProperty($child);
            if (!$name_property)
            {
                continue;
            }
            if ($child->{$name_property} == $name)
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


    /**
     * Deference a midgard link and return array of value and datatype
     *
     * @param object $object MgdSchema or midgard_metadata object
     * @param string $property_name name of the property to dereference
     * @param midgard_reflection_property $ref already instantiated midgard reflector
     */
    protected function dereferenceMgdLink($object, $property_name, $ref)
    {
        $ret = array();
        $ret[1] = \PHPCR\PropertyType::TYPENAME_WEAKREFERENCE;
        throw new Exception('Not implemented');
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
        $object_class = get_class($object);
        $ref =& $this->getMgdschemaReflector($object_class);

        // Normal properties
        $properties = $this->getMgdschemaProperties($mgdschema_type);
        foreach($properties as $property_name)
        {
            if ($ref->is_link($property_name))
            {
                $deref = $this->dereferenceMgdLink($object, $property_name, $ref);
                $node->{$property_name} = $deref[0];
                $node->{':' . $property_name} = $deref[1];
                unset($deref);
            }
            $node->{$property_name} = $object->{$property_name};
            $node->{':' . $property_name} = $this->getPropertyType($object_class, $property_name);
        }
        // MD properties
        $properties = $this->getMgdschemaProperties('midgard_metadata');
        $mdref =& $this->getMgdschemaReflector('midgard_metadata');
        foreach($properties as $property_name)
        {
            $jcr_name = "mgd:metadata:{$property_name}";
            if ($mdref->is_link($property_name))
            {
                $deref = $this->dereferenceMgdLink($object->metadata, $property_name, $mdref);
                $node->{$jcr_name} = $deref[0];
                $node->{':' . $jcr_name} = $deref[1];
                unset($deref);
            }
            $node->{$jcr_name} = $object->metadata->{$property_name};
            $node->{':' . $jcr_name} = $this->getPropertyType('midgard_metadata', $property_name);
        }
        // GUID is a special case
        $node->{'jcr:uuid'} = $object->guid;
        $node->{':jcr:uuid'} = \PHPCR\PropertyType::TYPENAME_STRING;
        
        //TODO: How to handle JCR primary and mixin types (for example almost all midgard objects are referenceable)

        $children = $this->getChildren($object);
        foreach ($children as $child)
        {
            // I don't quite understand what is being done here --rambo
            $name_property = $this->getNameProperty($child);
            if (!$name_property)
            {
                continue;
            }
            if (!$child->{$name_property})
            {
                continue;
            }
            $node->{$child->name} = new \StdClass();
        }

        return $node;
    }

    /**
     * Helper to get a midgard_reflection_property for given mgdschema type
     *
     * @param string $mgdschema_type the mgdschema class name
     * @return reference to the reflector object
     * @throws \PHPCR\RepositoryException in case of trouble
     */
    protected function &getMgdschemaReflector($mgdschema_type)
    {
        static $reflectors = array();
        // Safety against passing an object here
        if (is_object($mgdschema_type))
        {
            $mgdschema_type = \get_class($mgdschema_type);
        }
        if (isset($reflectors[$mgdschema_type]))
        {
            return $reflectors[$mgdschema_type];
        }
        $ref = new \midgard_reflection_property($mgdschema_type);
        if (!$ref)
        {
            throw new \PHPCR\RepositoryException(midgard_connection::get_error_string());
        }
        $reflectors[$mgdschema_type] = $ref;
        unset($ref);
        return $reflectors[$mgdschema_type];
    }

    /**
     * Gets an array usable with Jackalope\NodeTypeDefinition::fromArray for given MgdSchema name
     *
     * @param string $mgdschema_type name of the mgschema registered class
     * @return array usable Jackalope\NodeTypeDefinition::fromArray
     */
    public function getNodeTypeDefArray($mgdschema_type)
    {
        $ref =& $this->getMgdschemaReflector($mgdschema_type);
/**
 * The fromArray code for reference
 *
        $this->name = $data['name'];
        $this->isAbstract = $data['isAbstract'];
        $this->isMixin = $data['isMixin'];
        $this->isQueryable = $data['isQueryable'];!
        $this->hasOrderableChildNodes = $data['hasOrderableChildNodes'];
        $this->primaryItemName = $data['primaryItemName'] ?: null;
        $this->declaredSuperTypeNames = (isset($data['declaredSuperTypeNames']) && count($data['declaredSuperTypeNames'])) ? $data['declaredSuperTypeNames'] : array();
        $this->declaredPropertyDefinitions = new ArrayObject();
        foreach ($data['declaredPropertyDefinitions'] AS $propertyDef) {
            $this->declaredPropertyDefinitions[] = $this->factory->get(
                'NodeType\PropertyDefinition',
                array($propertyDef, $this->nodeTypeManager)
            );
        }
        
        
        $this->declaredNodeDefinitions = new ArrayObject();
        foreach ($data['declaredNodeDefinitions'] AS $nodeDef) {
            $this->declaredNodeDefinitions[] = $this->factory->get(
                'NodeType\NodeDefinition',
                array($nodeDef, $this->nodeTypeManager)
            );
        }
*/

        $data['name'] = $mgdschema_type;
        $data['hasOrderableChildNodes'] = true;


        $data['declaredPropertyDefinitions'] = array();
        $properties = $this->getMgdschemaProperties($mgdschema_type);
        foreach($properties as $property_name)
        {
            $data['declaredPropertyDefinitions'][] = $this->getPropertyDefArray($mgdschema_type, $property_name);
        }
        // Append metadata properties
        $properties = $this->getMgdschemaProperties('midgard_metadata');
        foreach($properties as $property_name)
        {
            $data['declaredPropertyDefinitions'][] = $this->getPropertyDefArray($mgdschema_type, $property_name, "mgd:metadata:{$property_name}");
        }
        /**
         * This should be defined by the very base types of JCR itself
        $data['declaredPropertyDefinitions'][] = array
        (
            'name' => 'jcr:uuid',
            'requiredType' => \PHPCR\PropertyType::TYPENAME_STRING,
            // TODO: Other defintion
            
        );
        */
        
        return $data;
    }

    /**
     * Get the list of properties for given MgdSchema type
     *
     * @param string $mgdschema_type the mgdschema classname (or object instance)
     * @return array of the property names (metadata, id and guid properties excluded)
     */
    protected function getMgdschemaProperties($mgdschema_type)
    {
        static $cache = array();
        $dummy = $this->getMgdDummyObject($mgdschema_type);
        $class = get_class($dummy);
        if (isset($cache[$class]))
        {
            return $cache[$class];
        }
        $properties = get_object_vars($dummy);
        unset($properties['metadata'], $properties['id'], $properties['guid']);
        $cache[$class] = array_keys($properties);
        unset($dummy, $properties);
        return $cache[$class];
    }

    /**
     * Gets an array usable with Jackalope\PropertyDefinition::fromArray for given MgdSchema name
     *
     * @param string $mgdschema_type name of the mgschema registered class
     * @param string $property_name name of the mgdschema property
     * @return array usable Jackalope\PropertyDefinition::fromArray
     */
    public function getPropertyDefArray($mgdschema_type, $property_name, $override_name = false)
    {
        $ref =& $this->getMgdschemaReflector($mgdschema_type);
/**
 * The fromArray code for reference
 *
        parent::fromArray($data);
        // begin parent
        $this->declaringNodeType = $data['declaringNodeType'];
        $this->name = $data['name'];
        $this->isAutoCreated = $data['isAutoCreated'];
        $this->isMandatory = isset($data['mandatory']) ? $data['mandatory'] : false;
        $this->isProtected = $data['isProtected'];
        $this->onParentVersion = $data['onParentVersion'];        
        // end parent
        
        $this->requiredType = $data['requiredType'];
        $this->isMultiple = isset($data['multiple']) ? $data['multiple'] : false;
        $this->isFullTextSearchable = isset($data['fullTextSearchable']) ? $data['fullTextSearchable'] : false;
        $this->isQueryOrderable = isset($data['queryOrderable']) ? $data['queryOrderable'] : false;
        $this->valueConstraints = isset($data['valueConstraints']) ? $data['valueConstraints'] : array();
        $this->availableQueryOperators = isset($data['availableQueryOperators']) ? $data['availableQueryOperators'] : array();
        $this->defaultValues = isset($data['defaultValues']) ? $data['defaultValues'] : array();
*/

        $data['name'] = $property_name;
        if ($override_name)
        {
            $data['name'] = $override_name;
        }
        $data['requiredType'] = $this->getPropertyType($mgdschema_type, $property_name);
        $data['multiple'] = false;
        return $data;
    }


    /**
     * Get a dummy object usable to get object property list from MgdSchema class name
     *
     * @param string $mgdschema_type MgdSchema class name
     */
    protected function getMgdDummyObject($mgdschema_type)
    {
        static $cache = array();
        // Prepend namespace in case it's not there
        if (is_object($mgdschema_type))
        {
            $mgdschema_type = get_class($mgdschema_type);
        }
        if (   is_string($mgdschema_type)
            && $mgdschema_type[0] !== '\\')
        {
            $mgdschema_type = '\\' . $mgdschema_type;
        }
        else
        {
            throw new Exception('Got funky argument');
        }
        if (isset($cache[$mgdschema_type]))
        {
            return $mgdschema_type;
        }
        $cache[$mgdschema_type] = new $mgdschema_type();
        return $cache[$mgdschema_type];
    }

    /**
     * Get the name of the property considered the path-name of the object
     *
     * @param mixed $mgdschema_type MgdSchema class name or object instance
     * @return string the name of the property or boolean false if it could not be determined
     */
    protected function getNameProperty($mgdschema_type)
    {
        static $cache = array();
        $dummy = $this->getMgdDummyObject($mgdschema_type);
        $class = get_class($dummy);
        if (isset($cache[$class]))
        {
            return $cache[$class];
        }
        if (\property_exists($dummy, 'name'))
        {
            $cache[$class] = 'name';
            return $cache[$class];
        }
        $cache[$class] = false;
        return $cache[$class];
    }

    /**
     * Get the \PHPCR\PropertyType for given mgdschema class property
     *
     * @param string $mgdschema_type name of the mgschema registered class
     * @param string $property_name name of the mgdschema property
     */
    protected function getPropertyType($mgdschema_type, $property)
    {
        $ref =& $this->getMgdschemaReflector($mgdschema_type);
        $type = $ref->get_midgard_type($property);
        
        // Link property handling
        if ($ref->is_link($property))
        {
            // TODO: Determine whether to use weak or hard reference
            return \PHPCR\PropertyType::TYPENAME_WEAKREFERENCE;
        }
   
        switch($type)
        {
            case MGD_TYPE_GUID:
                // Fall-through for now
            case MGD_TYPE_STRING:
                if ($property_name === $this->getNameProperty($mgdschema_type))
                {
                    return \PHPCR\PropertyType::TYPENAME_PATH;
                }
                return \PHPCR\PropertyType::TYPENAME_STRING;
                break;

            case MGD_TYPE_BOOLEAN:
                return \PHPCR\PropertyType::TYPENAME_BOOLEAN;
                break;

            case MGD_TYPE_INT:
                return \PHPCR\PropertyType::TYPENAME_LONG;
                break;

            case MGD_TYPE_UINT:
                return \PHPCR\PropertyType::TYPENAME_LONG;
                break;

            case MGD_TYPE_FLOAT:
                return \PHPCR\PropertyType::TYPENAME_DOUBLE;
                break;

            case MGD_TYPE_TIMESTAMP:
                return \PHPCR\PropertyType::TYPENAME_DATE;
                break;

            default:
                return \PHPCR\PropertyType::TYPENAME_UNDEFINED;
        }
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
            $object = \midgard_object_class::get_object_by_guid($guid);
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
    protected function getPathForMidgardObject($object)
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