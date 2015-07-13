# WTF is Pork.dbObject? #

It's an [Object-Relation mapper](http://en.wikipedia.org/wiki/Object-relational_mapping) for PHP 5 that attempts to be easy, fast, lightweight, uses optimized database queries and provides an easy Find() function very loosely based on rails'. Also, it has an automatic database analyzer and code generator: [Pork.Generator](http://www.schizofreend.nl/Pork.Generator): A PHP5 based database analyzer that automatically sees the different types of relations in your (MySQL) database and creates linked dbObjects for it.

# Why Pork.dbObject? #

Pork.dbObject was created initially because I am very lazy. I kept repeating myself writing the same old insert/select/update/delete queries for my database access. After seeing a demo for RoR with the scaffolding and the Find method I decided i could do that too, but better and with less code, and in PHP.


The Result is Pork.dbObject and Pork.Generator. One of the design principles for Pork.dbObject is that i did not want a lot of XML files in some kind of new dialect, nor weird commandline scripts or mandatory directory structures. Pork.dbObject just requires you to extend your objects from dbObject and add one function to your class constructor to setup the mappings to a table, that's it :-)

### For more information ###
Check the [forums](http://www.schizofreend.nl/forums/) or the [examples page](http://www.schizofreend.nl/Pork.dbObject/examples)

# New in v1.3.1: #

Latest version of Pork.dbObject

**- Rewritten dbconnection class to use true database abstraction via a
dbConnectionAdapter (currently includes SQLite and MySQL, write your own in minutes!)**

**- Updated dbObject to use new db abstractions.**

**- Now uses a settings class for settings.ini instead of a php file (for easer
maintenance)**

**- Updated dbObject to use new settings.ini**

**- Includes a custom logger class that handles your PHP / Pork errors and logs
them to a special database**

**- Updated dbObject to use new logger class. It's now way easier to see when you
do something wrong**

**- New FindCount and SearchCount functions to execute select count** where xx
functions

**- Optimized querybuilder and dbObject to be able to connect to a specific
database from settings.ini.**


# New in v1.2beta: #

**- Moved to Google Code**

**- PHPDoc function documentation**

**- Custom relations.**

---


A much requested feature in Pork.dbObject is the option to have custom field mappings (e.g. map Customers.ID to Contracts.Customer\_ID)

This is now solved by the addition of the addCustomRelation function:

```

	/**
	 * New function to add the custom relation mappings. Now you no longer need matching primary keys to have a connection.
	 * E.G Map Customers.ID to Contracts.Customer_ID 
	 *
	 * Usage: $this->addCustomRelation($targetclass, $sourceclassproperty, $targetclassProperty)
	 * Do not forget to do this in both classes. For a relation between a Customers and a Contracts object as shown above, you need to do the following:
	 * //class customer -> __construct()
	 * $this->addCustomRelation('Contract', 'ID', 'Customer_ID');
	 * // class contract -> __construct()
	 * $this->addCustomRelation('Customer', 'Customer_ID', 'ID');
	 * All Find() connect and disconnect functions work transparently with this new method. 
	 */
	function addCustomRelation($classname, $sourceproperty, $targetproperty)
	{
		$this->relations[$classname] = new stdClass();
		$this->relations[$classname]->relationType = RELATION_CUSTOM;
		$this->relations[$classname]->sourceProperty = $sourceproperty;
		$this->relations[$classname]->targetProperty = $targetproperty;
	}
```