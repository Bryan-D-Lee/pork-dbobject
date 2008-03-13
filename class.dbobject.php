<?php

/**
	
	Pork.dObject version 1.1
	By Jelle Ursem
	see pork-dbobject.sourceforge.net for more info
	
*/
define('RELATION_SINGLE', 'RELATION_SINGLE');
define('RELATION_FOREIGN', 'RELATION_FOREIGN');
define('RELATION_MANY', 'RELATION_MANY');
define('RELATION_NOT_RECOGNIZED', 'RELATION_NOT_RECOGNIZED');
define('RELATION_NOT_ANALYZED', 'RELATION_NOT_ANALYZED');

class dbObject
{
	var $databaseInfo, $databaseValues, $changedValues, $relations, $orderProperty, $orderDirection;

	/* This is  the function you use in the constructor of your objects to map fields to the database */
	public function __setupDatabase($table, $fields, $primarykey, $id=false)
	{
		$this->databaseInfo = new stdClass();
		$this->databaseInfo->table = $table;
		$this->databaseInfo->fields = $fields;
		$this->databaseInfo->primary = $primarykey;
		$this->databaseInfo->ID = $id;
		$this->databaseValues = array();
		$this->changedValues = array();
		$this->relations = array();
		$this->orderProperty = false;
		$this->orderDirection = false;
		if($id) $this->__init();
	}

	private function __init() 
	{
		if($this->databaseInfo->ID != false) {
			$fieldnames = implode(",", array_keys($this->databaseInfo->fields));
			$this->databaseValues = dbConnection::getInstance()->fetchRow("select {$fieldnames} from {$this->databaseInfo->table} where {$this->databaseInfo->primary} = {$this->databaseInfo->ID}", 'mysql_fetch_assoc');
		}
	}

	public function __get($property) { // catch the default getter and return the appropriate property
		$field = false;
		if(array_key_exists($property, get_object_vars($this))) return($this->$property);  // it's a private property
		$field = $this->fieldForProperty($property);					  // are we calling the 'mapped' way?
		if(!$field) $field = (array_key_exists($property, $this->databaseInfo->fields)) ? $property : false;
		if($field != false && array_key_exists($field, $this->changedValues)) return($this->changedValues[$field]); // this is an updated property, return it.
		if($field != false && is_array($this->databaseValues) && array_key_exists($field, $this->databaseValues)) return($this->databaseValues[$field]); // return the original value from the database
	}


	public function __set($property, $value) { // catch the default setter
		if($this->hasProperty($property)) $this->changedValues[$this->fieldForProperty($property)] = $value;	
	}	

	public function __sleep() 
	{
		$fields = array_keys(get_object_vars($this));
		return($fields);
	}
	
	private function hasProperty($property) { // does the object have the property $property ?
		foreach($this->databaseInfo->fields as $key=>$value) {		
			if(strtolower($key) == strtolower($property) || strtolower($value) == strtolower($property)) return true;
		}	
		return false;
	}

	private function fieldForProperty($property) { // get db field by it's property name
		foreach($this->databaseInfo->fields as $key=>$value) {		
			if(strtolower($key) == strtolower($property) || strtolower($value) == strtolower($property)) return $key;
		}	
		return false;
	}
	

	public function DeleteYourself() { //deletes the current object from database.
		if($this->databaseInfo->ID !== false) {
			dbConnection::getInstance()->query("delete from {$this->databaseInfo->table} where {$this->databaseInfo->primary} = {$this->databaseInfo->ID}");
		}
	}

	public function setOrderProperty($field, $order='ASC') { // set the default property to use with order by queries
		$this->orderProperty = $field;
		$this->orderDirection = $order;
	}

	/**
		Insert this object into the database:
		* prepare the query with just a null value for primary key
		* append the changed fields and (addslash'd)values of this object if needed
		* execute the query
	*/
	private function InsertNew()
	{
		$insertfields = $this->databaseInfo->primary;
		$insertValues = 'null';
		
		if (sizeof($this->changedValues) > 0) { // do we have any new-set values?
			$filteredValues = $this->changedValues;
			$insertfields .= ', '.implode(",", array_keys($filteredValues));
			foreach ($filteredValues as $property=>$value) { // append each value escaped to the query
				$insertValues .= ', "'.addslashes($value).'"';
				$this->databaseValues[$property] = $this->changedValues[$property]; // and store it so we don't save it again
			}
			$this->changedValues = array(); // then clear the changedValues 
		}
		$this->databaseInfo->ID = dbConnection::getInstance()->query("insert into {$this->databaseInfo->table} ({$insertfields}) values ($insertValues);");
		$this->databaseValues[$this->databaseInfo->primary] = $this->databaseInfo->ID; // update the primary key
		return($this->databaseInfo->ID); // and return it 
	}

	public function Save() 
	{
		if(sizeof($this->changedValues) > 0 && $this->databaseInfo->ID == false) { // it's a new record for the db
			$id = $this->InsertNew();
			$this->analyzeRelations(); // re-analyze the relation types so we can use Find()
			return $id;
		}
		elseif ($this->changedValues != false) { // otherwise just build the update query
			$updateQuery = "";
			$filteredValues = $this->changedValues;
			foreach ($filteredValues as $property=>$value) {
				$updateQuery .= ($updateQuery != "") ? ", " : "";
				$updateQuery .=" {$property} = '".addslashes($value)."'";
				$this->databaseValues[$property] = $this->changedValues[$property]; // store the value so we don't have to save it again
			}
			dbConnection::getInstance()->query("update {$this->databaseInfo->table} set {$updateQuery} where {$this->databaseInfo->primary} = {$this->databaseInfo->ID}");
			$this->changedValues = array(); 
			return($this->databaseInfo->ID);
		}	
		return false;
	}
		
	/**
		Add a new relation to the relation list and set it to be analyzed if used
	*/
	public function addRelation($classname, $connectorclassname=false) 
	{
		$this->relations[$classname] = new stdClass();
		$this->relations[$classname]->relationType = RELATION_NOT_ANALYZED;
		if($connectorclassname != false) $this->relations[$classname]->connectorClass = $connectorclassname;
		if($this->databaseInfo->ID != false) $this->analyzeRelations();		
	}


	/** 
		This is where the true magic happens. It will analyze what kind of Db relation we're using.
	*/
	private function analyzeRelations() 
	{
		foreach($this->relations as $classname=>$info) {
			if(is_subclass_of($classname, 'dbObject')) {// the class to connect is a dbObject
				$obj = new $classname(false);
				$info->className = $classname;
				if(array_key_exists('connectorClass', get_object_vars($info)) && $info->connectorClass != '' && is_subclass_of($info->connectorClass, 'dbObject')) { // this class has a connector class. It should be a many:many relation
					$connector = $info->connectorClass;
					$connectorobj = new $connector(false);
					if(array_key_exists($this->databaseInfo->primary, $connectorobj->databaseInfo->fields) && array_key_exists($obj->databaseInfo->primary, $connectorobj->databaseInfo->fields)) {
						$info->relationType = RELATION_MANY; // yes! The primary key of the relation now appears in this object, the connector class and one of the connected class. it's a many:many relation
					} 
					else { 
						unset($info->connectorClass); // it's not connected to our relations
					}
				}
				if( $info->relationType == RELATION_NOT_ANALYZED &&
					array_key_exists($obj->databaseInfo->primary, $this->databaseInfo->fields) && array_key_exists($this->databaseInfo->primary, $obj->databaseInfo->fields)) {
					$info->relationType = RELATION_SINGLE; // if the primary key of the connected object exists in this object and the primary key of this object exists in the connected object it's a 1:1 relation
				}
				elseif($info->relationType == RELATION_NOT_ANALYZED 
					&& (array_key_exists($this->databaseInfo->primary, $obj->databaseInfo->fields) && !array_key_exists($obj->databaseInfo->primary, $this->databaseInfo->fields) || !array_key_exists($this->databaseInfo->primary, $obj->databaseInfo->fields) && array_key_exists($obj->databaseInfo->primary, $this->databaseInfo->fields)) ) {
						$info->relationType = RELATION_FOREIGN;	// if the primary key of the connected object exists in this object (or the other way around), but the primary key of this object does not exist in the connected object (or the other way around) it's a many:1 or 1:many relation		
				}
				elseif($info->relationType == RELATION_NOT_ANALYZED) {
					$info->relationType = RELATION_NOT_RECOGNIZED;  // we don't recognize this type of relation. 
				}
				$this->relations[$classname] = $info;
			}
			else
			{
				unset($this->relations[$classname]); // tried to connect a non-dbobject object.
			}
		}	
	}

	
	/*
		This connects 2 dbObjects together, with a connector class if needed.
	*/
	public function Connect($object) 
	{
		$className = get_class($object);
		if($this->databaseInfo->ID == false) $this->Save(); // save both objects if they are new
		if($object instanceof dbObject && $object->databaseInfo->ID == false) $object->Save(); 	
		if(array_key_exists($className, $this->relations)) {
			switch($this->relations[$className]->relationType)
			{
				case RELATION_NOT_ANALYZED:
					$this->analyzeRelations(); // if we didn't run the analyzer yet, run it.
					$this->Connect($object); // run connect function again.
				break;
				case RELATION_SINGLE: // link the 2 objects' primary keys
					$this->changedValues[$object->databaseInfo->primary] = $object->databaseInfo->ID;
					$object->changedValues[$this->databaseInfo->primary] = $this->databaseInfo->ID;	 
				break;
				case RELATION_FOREIGN: // determine wich one needs to have the primary key set for the 1:many or many:one relation 
					if(array_key_exists($this->databaseInfo->primary, $object->databaseInfo->fields)) {
						$object->changedValues[$this->databaseInfo->primary] = $this->databaseInfo->ID;
					}
					elseif(array_key_exists($object->databaseInfo->primary, $this->databaseInfo->fields)) {
						$this->changedValues[$object->databaseInfo->primary] = $object->databaseInfo->ID;
					}
				break;
				case RELATION_MANY: // create a new connector class, set both primary keys and save it.
					$connector = $this->relations[$className]->connectorClass;
					$connector = new $connector(false);
					$property = $connector->databaseInfo->fields[$this->databaseInfo->primary];
					$connector->$property = $this->databaseInfo->ID;
					$property = $connector->databaseInfo->fields[$object->databaseInfo->primary];
					$connector->$property = $object->databaseInfo->ID;
					$connector->Save();
				break;
			}
			$this->Save(); // save both objects to store changed values.
			$object->Save();
		}	
	}

	// see Connect() for how this works, it's the other way around.
	public function Disconnect($object, $id=false) 
	{
		if(!$object && !$id) return;
		if(!$object instanceof dbObject && $id != false) {
			$object = new $object(false);
			$object->databaseInfo->ID = $id; // a tweak to disconnect an uninitialized object so that it doesn't have to fetch the whole row first.
		}
		$className = get_class($object);
		if(array_key_exists($className, $this->relations)) {
			switch($this->relations[$className]->relationType)
			{
				case RELATION_SINGLE:
					$this->changedValues[$object->databaseInfo->primary] = '';
					$object->changedValues[$this->databaseInfo->primary] = '';
					$this->Save();
					$object->Save();
				break;
				case RELATION_FOREIGN:
					if(array_key_exists($this->databaseInfo->primary, $object->databaseInfo->fields)) {
						$object->changedValues[$this->databaseInfo->primary] = '';
						$object->Save();
					}
					elseif(array_key_exists($object->databaseInfo->primary, $this->databaseInfo->fields)) {
						$this->changedValues[$object->databaseInfo->primary] = '';
						$this->Save();
					}
				break;
				case RELATION_MANY:
					$input = dbObject::search($this->relations[$className]->connectorClass, array($object->databaseInfo->primary => $object->databaseInfo->ID, $this->databaseInfo->primary => $this->databaseInfo->ID)); // search for a connector with both primaries
					if($input) $input[0]->deleteYourSelf();
				break;
			}
		}	
	}

	/**
		Checks if this is a 'connecting' object between 2 tables by checking if the passed classname is a connection class.
	*/
	private function isConnector($className)
	{
		foreach ($this->relations as $key => $val) { // walk all relations
			if(array_key_exists('connectorClass', get_object_vars($val)) && $val->connectorClass == $className) return true; 
		}
		return false;	
	}

	public function Import($values) { // import a pre-filled object (like a table row)
		$this->databaseValues = $values;
		$this->databaseInfo->ID = (!empty($values[$this->databaseInfo->primary])) ? $values[$this->databaseInfo->primary] : false;
	}


	/**
		Imports an array of e.g. db rows and returns filled instances of $className
		This will not run the analyzerelations or other stuff for performance and recursivity reasons.
	*/
	public static function importArray($className, $input) 
	{
		$output = array();
		foreach ($input as $array) 
		{
			$elm = new $className(false);
			$elm->Import($array);
			if($elm->ID != false) $output[] = $elm;
		}
		return(sizeof($output) > 0 ? $output : false);	
	}

	/* Is the passed class a relation of $this? */
	private function isRelation($class) 
	{
		if (strtolower($class) == strtolower(get_class($this))) { return(get_class($this)); }
		if(!empty($this->relations)){
			foreach($this->relations as $key=>$val) if(strtolower($class) == strtolower($key)) return($key);
		}
		return false;
	}

	/* 
	The awesome find function. Creates a QueryBuilder Object wich creates a Query to find all objects for your filters.
	Syntax for the filters array:
	Array(
			'ID > 500', // just a key element, it will detect this, map the fields and just execute it.
			'property'=> 'value' // $property of $classname has to be $value 
			Array('ClassName'=> array('property'=>'value')// Filter by a (relational) class's property. You can use this recursively!!
	) 
	Will return false if it finds nothing.
	*/
	public function Find($className, $filters=array(), $extra=array(), $justThese=array()) 
	{
		$originalClassName = ($className instanceof dbObject) ? get_class($className) : $className;
		$class = new $originalClassName();
		if($originalClassName != get_class($this) && $this->ID != false) {
			$filters["ID"] = $this->ID;
			$filters = array(get_class($this) => $filters);	
		}
		$builder = new QueryBuilder($originalClassName, $filters, $extra, $justThese);
		$input = dbConnection::getInstance()->fetchAll($builder->buildQuery(), 'mysql_fetch_assoc');
		return(dbObject::importArray($originalClassName, $input));
	}
	
	/* Static Find function
		Use this if you want to just run dbObject::Search(classname, filters) without creating an empty class in your code. It will automatically create the classname and execute the search.
	*/
	static function Search($className, $filters=array(), $extra=array(), $justThese=array())
	{
		$class = new $className();
		if($class instanceOf dbObject)
		{
			return($class->Find($className, $filters, $extra, $justThese));
		}
	}

	public function __destruct()
	{
		$this->Save(); // try to save the object if changed.
	}
}

/* The helper class that analyzes what joins to use in the select queries */
class QueryBuilder 
{
	var $class, $fields, $filters, $extras, $justthese, $joins, $groups, $wheres, $limit, $orders;

	public function __construct($class, $filters=array(), $extras=array(), $justthese=array())
	{
		$this->class= $class;
		$this->filters = $filters;
		$this->extras = $extras;
		$this->wheres = array();
		$this->joins = array();
		$this->fields = array();
		$this->orders = array();
		$this->groups = array();
		if(!($this->class instanceof dbObject)) $this->class = new $class();
		$tableName = $this->class->databaseInfo->table;
		if(sizeof($justthese) == 0) { // if $justthese is not passed, use all fields from $class->databaseInfo->fields
			$fields = array_keys($this->class->databaseInfo->fields);
			foreach($fields as $property) $this->fields[] = $tableName.'.'.$this->class->fieldForProperty($property);
		}
		else { // otherwise, use only the fields from $justthese
			foreach($justthese as $property) $this->fields[] = $tableName.'.'.$this->class->fieldForProperty($property);
		}
		if(sizeof($filters) > 0 )
		{
			foreach($filters as $property=>$value) $this->buildFilters($property, $value, $this->class);
		}
		$this->buildOrderBy();
		
	}

	private function buildFilters($what, $value, $class)
	{
		if(is_array($value)) {  // filter by a property of a subclass
			foreach($value as $key=>$val) $this->buildFilters($key, $val, $what);
			$this->buildJoins($what, $this->class);
		}
		elseif(is_numeric($what)) { // it's a custom whereclause (not just $field=>$value)		
			if((!$class instanceof dbObject)) $class = new $class();
			$this->wheres[] = $this->mapFields($value, $class);
		}
		else { // standard $field=>$value whereclause. Prefix with tablename for speed.
			if((!$class instanceof dbObject)) $class = new $class();
			$what = $class->fieldForProperty($what);
			$this->wheres[] = "{$class->databaseInfo->table}.{$what} = '{$value}'";
		}
	}

	private function buildOrderBy()	// filter the 'extras' paramter for order by, group by and limit clauses.
	{
		$hasorderby = false;
		foreach($this->extras as $key=>$extra) {
			if(strpos(strtoupper($extra), 'ORDER BY') !== false) {
				$this->orders[] = $this->mapFields(str_replace('ORDER BY', "", strtoupper($extra)), $this->class);
				unset($this->extras[$key]);
			}
			if(strpos(strtoupper($extra), 'LIMIT') !== false) {
				unset($this->extras[$key]);
				$this->limit = $this->mapFields($extra, $this->class);
			}
			if(strpos(strtoupper($extra), 'GROUP BY') !== false) { 
				$this->groups[] = $this->mapFields(str_replace('GROUP BY', "", strtoupper($extra)), $this->class);
				unset($this->extras[$key]);
			}
		}
		if($this->class->orderProperty && $this->class->orderDirection && sizeof($this->orders) == 0) {
			$this->orders[] = $this->mapFields("{$this->class->orderProperty} {$this->class->orderDirection}", $this->class);
		}
	}

	
	private function mapFields($query, $object) // map the 'pretty' fieldnames to db table fieldnames.
	{
		$words = preg_split("/([\s|,]+)/", $query, -1, PREG_SPLIT_DELIM_CAPTURE);	
		if(!empty($words)) {
			foreach($words as $key=>$val) { 
				if(strlen(trim($val)) < 1) continue;
				if(strpos($val, '.') !== false) {
					$expl = explode(".", $val);
					if(sizeof($expl) == 2 && $expl[0] == $object->databaseInfo->table)  $val = $expl[1];
					else continue;
				}
				if($object->hasProperty($val)) { 
					$words[$key] = $object->databaseInfo->table.'.'.$object->fieldForProperty($val);
				}
			} 
		}
		return(implode("", $words));
	}

	private function buildJoins($class, $parent=false) // determine what joins to use
	{
		if(!$parent) return;	// first do some checks for if we have uninitialized classnames
		if(!($class instanceof dbObject)) $class = new $class(); 
		$className = get_class($class);
		if(!($parent instanceof dbObject)) $parent = new $parent();
		switch($parent->relations[$className]->relationType) { // then check the relationtype
			case RELATION_NOT_ANALYZED:							// if its not analyzed, it's new. Save + analyze + re-call this function.
				if(sizeof($class->changedValues) > 0) $class->Save();
				$parent->analyzeRelations();
				return($this->buildJoins($class, $parent));
			break;
			case RELATION_SINGLE:
			case RELATION_FOREIGN:								// it's a foreign relation. Join the appropriate table.
				if($class->hasProperty($parent->databaseInfo->primary)) 
				{
					$this->joins[] = "LEFT JOIN \n\t {$class->databaseInfo->table} on {$parent->databaseInfo->table}.{$parent->databaseInfo->primary} = {$class->databaseInfo->table}.{$parent->databaseInfo->primary}";
				}
				else if($parent->hasProperty($class->databaseInfo->primary)) 
				{
					$this->joins[] = "LEFT JOIN \n\t {$class->databaseInfo->table} on {$class->databaseInfo->table}.{$class->databaseInfo->primary} = {$parent->databaseInfo->table}.{$class->databaseInfo->primary}";
				}
			break;
			case RELATION_MANY:									// it's a many:many relation. Join the connector table and then the other one.
				$connectorClass = $parent->relations[$className]->connectorClass;
				$conn = new $connectorClass(false);
				$this->joins[] = "LEFT JOIN \n\t {$conn->databaseInfo->table} on  {$conn->databaseInfo->table}.{$parent->databaseInfo->primary} = {$parent->databaseInfo->table}.{$parent->databaseInfo->primary}";
				$this->joins[] = "LEFT JOIN \n\t {$class->databaseInfo->table} on {$conn->databaseInfo->table}.{$class->databaseInfo->primary} = {$class->databaseInfo->table}.{$class->databaseInfo->primary}";
			break;
			default:
				$errmsg = "<p style='color:red'>Error: class ".get_class($parent)." probably has no relation defined for class {$className}  or you did something terribly wrong...</p>";
				throw_error($errmsg);
				throw_error($parent->relations[$className]);
				echo($errmsg);
			break;
		}
	}
	
	public function buildQuery() // joins all the previous stuff together.
	{
		$where = (sizeof($this->wheres) > 0) ? ' WHERE '.implode(" \n AND \n\t", $this->wheres) : '';
		$order = (sizeof($this->orders) > 0) ? ' ORDER BY '.implode(", ", $this->orders) : '' ;
		$group = (sizeof($this->groups) > 0) ? ' GROUP BY '.implode(", ", $this->groups) : '' ;
		$query = 'SELECT '.implode(", \n\t", $this->fields)."\n FROM \n\t".$this->class->databaseInfo->table."\n ".implode("\n ", $this->joins).$where.' '.$order.' '.$group.' '.$this->limit;
		return($query);
	}
}

?>