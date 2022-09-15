# Doctrine FileMaker Data API driver #

A Doctrine driver to interact with FileMaker using the v17+ Data API v1.

## Installation ##

    composer require matatirosoln/doctrine-fm-data-api-driver

**Note**: We recently update the version of Guzzle used by this library to v7 which requires PHP >= 7.2. If you are still working with an earlier version of PHP then require version `^0.7.5` of this library.         
## Configuration ##
    
In your Doctrine configuration comment out 

    driver: xxxx
and replace it with

    driver_class: MSDev\DoctrineFMDataAPIDriver\FMDriver
    
## Conventions ##

1. Create a model to represent a FileMaker **layout**. Set the name of the layout as the model table.
    
        /**
         * Keyword
         *
         * @Table(name="Keyword")
         * @Entity(repositoryClass="Repository\KeywordRepository")
         */
         class Keyword
            
2. In your model create an id field which is mapped to the special rec_id pseudo field

        /**
         * @var int
         *
         * @Column(name="rec_id", type="integer")
         */
        private $id;
     
3. Create your 'actual' ID field, as used for relationships, as a separate property of your model. Set its GeneratedValue strategy to be `Custom` which will mean that Doctrie will wait for FM to assign that value - the assumption being that this is an auto-enter calc field (probaby Get(UUID)). You then need to specifiy the CustomIdGenerator and set this to `MSDev\DoctrineFMDataAPIDriver\FMIdentityGenerator` so that the value is returned as a string.  
   
       /**
        * @var string
        *
        * @Column(name="__pk_ClientID", type="string" length=255)
        * @Id
        * @GeneratedValue(strategy="CUSTOM")
        * @CustomIdGenerator(class="MSDev\DoctrineFMDataAPIDriver\FMIdentityGenerator")
        */
       private $uuid;
       
   Alternatively you could generate the UUIDs in your model constructor (using for example [ramsey/uuid](https://github.com/ramsey/uuid)). In this case you'd end up with something like
   
        /**
         * @var string
         *
         * @Column(name="__pk_CanvasID", type="string", length=255)
         * @Id
         */
         private $uuid;
         
         public function __construct()
         {
             $this->uuid = Uuid::uuid4()->toString();
         }
       
4. Add other properties as required. To access related fields on your layout enclose the field name in single quotes in the column mapping.
     
         /**
          * @var string
          *
          * @Column(name="'absCon::email'", type="string", length=255)
          */
         private $contactEmail;

5. If you need access to the record modification ID you can add the special mod_id pseudo property

        /**
         * @var int
         *
         * @Column(name="mod_id", type="integer")
         */
        private $modId;
        

## Considerations ##

1. Because of the way in which more 'conventional' databases handle relationships, there is no concept of a portal. To access related data create a corresponding model for that table (layout) and create standard Doctrine relationships (OneToOne, OneToMany, ManyToOne etc).
2. If your model contains calculation fields you will run into issues when trying to create a new record, since Doctrine will try and set those fields to null. One 'solution' to this is to create a 'stub' of your model which contains only the fields which are necessary to create a new record and to instantiate that for record creation. If you head down this route you'll likely want to create an interface which both your stub and your real entity implement so that you can typehint appropriately.
3. If you wish to perform an IN query, then it needs to be the last `andWhere` set in the query builder so that the previous conditions will be applied to all of the query objects which the Data API requires to simulate an IN query.  
 
## See also ##
 
This driver has been developed for use within Symfony applications (because that's what we do). The [Doctrine FileMaker bundle](https://github.com/matatirosolutions/doctrine-filemaker-driver-bundle "Doctrine FileMaker bundle") creates services to access scripts, containers, etc. 
