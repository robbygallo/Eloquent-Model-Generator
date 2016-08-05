<?php

namespace User11001\EloquentModelGenerator\Console;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Way\Generators\Commands\GeneratorCommand;
use Way\Generators\Generator;
use Way\Generators\Filesystem\Filesystem;
use Way\Generators\Compilers\TemplateCompiler;
use Illuminate\Contracts\Config\Repository as Config;
use DB;

class GenerateModelsCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'models:generate';

    protected $softDelete = [];
    protected $timestamps = [];
    protected $classMethods = [];

    protected $seeds = [];

    private static $namespace;
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate Eloquent models from an existing table structure.';

    private $schemaGenerator;
    /**
     * @param Generator        $generator
     * @param Filesystem       $file
     * @param TemplateCompiler $compiler
     * @param Config           $config
     */
    public function __construct(
        Generator $generator,
        Filesystem $file,
        TemplateCompiler $compiler,
        Config $config
    ) {
        $this->file = $file;
        $this->compiler = $compiler;
        $this->config = $config;

        parent::__construct($generator);
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['tables', InputArgument::OPTIONAL, 'A list of Tables you wish to Generate Migrations for separated by a comma: users,posts,comments'],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        //shameless copy
        return [
            ['connection', 'c', InputOption::VALUE_OPTIONAL, 'The database connection to use.', $this->config->get('database.default')],
            ['tables', 't', InputOption::VALUE_OPTIONAL, 'A list of Tables you wish to Generate Migrations for separated by a comma: users,posts,comments'],
            ['path', 'p', InputOption::VALUE_OPTIONAL, 'Where should the file be created?'],
            ['namespace', 'ns', InputOption::VALUE_OPTIONAL, 'Explicitly set the namespace'],
            ['overwrite', 'o', InputOption::VALUE_NONE, 'Overwrite existing models ?'],
        ];
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        //0. determine destination folder
        $destinationFolder = $this->getFileGenerationPath();

        //1. fetch all tables
        $this->info("\nFetching tables...");
        $this->initializeSchemaGenerator();
        $tables = $this->getTables();

        //2. for each table, fetch primary and foreign keys
        $this->info('Fetching table columns, primary keys, foreign keys');
        $prep = $this->getColumnsPrimaryAndForeignKeysPerTable($tables);

        //3. create an array of rules, holding the info for our Eloquent models to be
        $this->info('Generating Eloquent rules');
        $eloquentRules = $this->getEloquentRules($tables, $prep);

        //4. Generate our Eloquent Models
        $this->info("Generating Eloquent models\n");
        $this->generateEloquentModels($destinationFolder, $eloquentRules);

        $this->info("\nAll done!");
    }

    public function getTables()
    {
        $schemaTables = $this->schemaGenerator->getTables();

        $specifiedTables = $this->option('tables');

        //when no tables specified, generate all tables
        if (empty($specifiedTables)) {
            return $schemaTables;
        }

        $specifiedTables = explode(',', $specifiedTables);

        $tablesToGenerate = [];
        foreach ($specifiedTables as $specifiedTable) {
            if (!in_array($specifiedTable, $schemaTables)) {
                $this->error("specified table not found: $specifiedTable");
            } else {
                $tablesToGenerate[$specifiedTable] = $specifiedTable;
            }
        }

        if (empty($tablesToGenerate)) {
            $this->error('No tables to generate');
            die;
        }

        return array_values($tablesToGenerate);
    }

    private function generateEloquentModels($destinationFolder, $eloquentRules)
    {
        //0. set namespace
        self::$namespace = $this->getNamespace();

        foreach ($eloquentRules as $table => $rules) {
            try {
                $this->generateEloquentModel($destinationFolder, $table, $rules);
            } catch (Exception $e) {
                $this->error("\nFailed to generate model for table $table");

                return;
            }
        }
		
		$this->info('--- ');
		$this->info('Table seeds');
		$this->info(implode("\n", $this->seeds['table']));
		$this->info('--- ');
		$this->info('Pivot seeds');
		$this->info(implode("\n", $this->seeds['pivot']));
    }

    private function generateEloquentModel($destinationFolder, $table, $rules)
    {

		$modelName = $this->generateModelNameFromTableName($table);
		
		/*
		try	{
			\Artisan::call('make:seeder', [
				'name' => sprintf('%sTableSeeder', $modelName)
			]);
		} catch (\Exception $e) {
			echo $e->getMessage();
		}
		*/

		
 

        if (!preg_match('/pivot/i', $table)) {
            //1. Determine path where the file should be generated
            
            $filePathToGenerate = $destinationFolder.'/'.$modelName.'.php';

            $canContinue = $this->canGenerateEloquentModel($filePathToGenerate, $table);
            if (!$canContinue) {
                return;
            }

            //2.  generate relationship functions and fillable array
            $hasMany = $rules['hasMany'];
            $hasOne = $rules['hasOne'];
            $belongsTo = $rules['belongsTo'];
            $belongsToMany = $rules['belongsToMany'];

            $fillable = implode(",\n\t\t", $rules['fillable']);

            $belongsToFunctions = $this->generateBelongsToFunctions($table, $belongsTo);
            $belongsToManyFunctions = $this->generateBelongsToManyFunctions($table, $belongsToMany);
            $hasManyFunctions = $this->generateHasManyFunctions($table, $hasMany);
            $hasOneFunctions = $this->generateHasOneFunctions($table, $hasOne);

            $functions = $this->generateFunctions([
                $belongsToFunctions,
                $belongsToManyFunctions,
                $hasManyFunctions,
                $hasOneFunctions,
            ]);

            // use classes
            $useClasses = $this->getUseClasses($table);
            $validations = $this->getValidations($table);
            $softDeleteTrait = $this->getSoftDeleteTrait($table);
            $timestamps = $this->timestamps[$table];

            //3. prepare template data
            $templateData = array(
                'NAMESPACE' => self::$namespace,
                'USECLASSES' => $useClasses,
                'SOFTDELETETRAIT' => $softDeleteTrait,
                'NAME' => $modelName,
                'TABLENAME' => $table,
                'TIMESTAMPS' => $timestamps,
                'RULES' => $validations['rules'],
                'MESSAGES' => $validations['messages'],
                'FILLABLE' => $fillable,
                'FUNCTIONS' => $functions,
            );

            $templatePath = $this->getTemplatePath();

            //run Jeffrey's generator
            $this->generator->make(
                $templatePath,
                $templateData,
                $filePathToGenerate
            );
            $this->info("Generated model for table $table");

			$this->seeds['table'][] = sprintf('artisan make:seeder %sTableSeeder', $modelName);
        } else {
			$this->seeds['pivot'][] = sprintf('artisan make:seeder %sTableSeeder', $modelName);
		}
    }

    private function canGenerateEloquentModel($filePathToGenerate, $table)
    {
        $canOverWrite = $this->option('overwrite');
        if (file_exists($filePathToGenerate)) {
            if ($canOverWrite) {
                $deleted = unlink($filePathToGenerate);
                if (!$deleted) {
                    $this->warn("Failed to delete existing model $filePathToGenerate");

                    return false;
                }
            } else {
                $this->warn("Skipped model generation, file already exists. (force using --overwrite) $table -> $filePathToGenerate");

                return false;
            }
        }

        return true;
    }

    private function getNamespace()
    {
        $ns = $this->option('namespace');
        if (empty($ns)) {
            $ns = env('APP_NAME', 'App\Models');
        }

        //convert forward slashes in the namespace to backslashes
        $ns = str_replace('/', '\\', $ns);

        return $ns;
    }

    private function generateFunctions($functionsContainer)
    {
        $f = '';
        foreach ($functionsContainer as $functions) {
            $f .= $functions;
        }

        return $f;
    }

    public function getUseClasses($table)
    {
        //$useClasses = "use Illuminate\Database\Eloquent\Model;";
        $useClasses = "use App\Models\BaseModel;";
        if ($this->softDelete[$table]) {
            $useClasses .= "\nuse Illuminate\Database\Eloquent\SoftDeletes;\n";
        }

        return $useClasses;
    }

    public function getValidations($table)
    {
        $column_names = [];
        $rules = '';

        $columns = DB::select("DESCRIBE `{$table}`");

		
		$query = "SELECT REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME = '{$table}'";
		$keys = DB::select($query);

		
		$messages = [];
        foreach ($columns as $column) {
			if($column->Key != 'PRI') {
				foreach ($keys as $key) {
				    if (!empty($key->REFERENCED_TABLE_NAME)) {

				        if ($column->Field == $key->COLUMN_NAME) {
				            $column->rule = "exists:{$key->REFERENCED_TABLE_NAME},{$key->REFERENCED_COLUMN_NAME}";
				            break;
				        } 
				    }
				}


		        $validators = [];

		        $column_names[] = $column->Field;

				if (isset($column->rule)) {
		            $validators[] = $column->rule;
					$messages[] = sprintf("'%s.unique' => 'must_be_unique',", $column->Field);
		        }

				if (strpos($column->Type, 'tinyint(') !== false) {
		            $validators[] = 'boolean';

					$messages[] = sprintf("'%s.boolean' => 'must_be_boolean',", $column->Field);
		        } else if (strpos($column->Type, 'int(') !== false) {
		            $validators[] = 'integer';
					$messages[] = sprintf("'%s.integer' => 'must_be_integer',", $column->Field);
		            if (strpos($column->Type, 'unsigned') !== false) {
		                $validators[] = 'min:0';
					$messages[] = sprintf("'%s.min' => 'must_min %s',", $column->Field, 0);
		            }
		        }

		        if ($column->Null == 'NO') {
		            if (strpos($column->Type, 'varchar(') === false && strpos($column->Type, 'text') === false) {
		                $validators[] = 'required';
						$messages[] = sprintf("'%s.required' => 'must_required',", $column->Field);
		            }
		        }

		        if (strpos($column->Type, 'varchar(') !== false) {
		            $length = str_replace(')', '', str_replace('varchar(', '', $column->Type));
		            $validators[] = 'string';
		            $validators[] = "max:$length";
					$messages[] = sprintf("'%s.string' => 'must_string',", $column->Field);
					$messages[] = sprintf("'%s.max' => 'must_max %s',", $column->Field, $length);
		        }
		        if (strpos($column->Type, 'text') !== false) {
		            $validators[] = 'string';
					$messages[] = sprintf("'%s.text' => 'must_string',", $column->Field);
		        }
		        if ($column->Type == 'date' || $column->Type == 'datetime' || $column->Type == 'timestamp') {
		            $validators[] = 'date';
					$messages[] = sprintf("'%s.date' => 'must_date',", $column->Field);
		        }
		        if (strpos($column->Type, 'decimal(') !== false || strpos($column->Type, 'double(') !== false || strpos($column->Type, 'float(') !== false) {
		            $validators[] = 'numeric';
					$messages[] = sprintf("'%s.numeric' => 'must_numeric',", $column->Field);
		        }

		        $validation = '';
		        if (count($validators) > 0) {
		            $validation = implode('|', $validators);
		        }

		        if (count($column_names) > 1) {
		            $rules .= "\n		";
		        }
		        $rules .= "'{$column->Field}' => '{$validation}',";
			}
        }


		$messages = implode("		\n", $messages);

        return ['rules' => $rules, 'messages' => $messages];
    }

    public function getSoftDeleteTrait($table)
    {
        $softDeleteTrait = "\n\tuse SoftDeletes;\n";

		if(isset($this->classMethods[$table]) && count(isset($this->classMethods[$table]))) {
			$softDeleteTrait .= '
    public static function boot()
    {
        parent::boot();';
        $softDeleteTrait .= '

        static::deleted(function ('.'$item'.') {';
        if (isset($this->classMethods[$table])) {
            foreach ($this->classMethods[$table] as $method) {
                $softDeleteTrait .=  sprintf("\n\t\t\t".'$item->%s()->delete();', $method);
            }
        }
        $softDeleteTrait .=  '
        });';

        $softDeleteTrait .= '

        static::restored(function ('.'$item'.') {';
        if (isset($this->classMethods[$table])) {
            foreach ($this->classMethods[$table] as $method) {
                $softDeleteTrait .=  sprintf("\n\t\t\t".'$item->%s()->withTrashed()->get()->restore();', $method);
            }
        }
        $softDeleteTrait .=  '
        });';
        $softDeleteTrait .=  '
    }
    ';
		}

        

        return $softDeleteTrait;
/*
            if ($this->softDelete[$table]) {
                return <<<SDT

    use SoftDeletes;


    public static function boot()
    {
        parent::boot();

        static::deleted(function ($$item) {
            $methods
        });

        static::restored(function ($$item) {
            $methods
        });
    }

SDT;
            }
        }
*/

        return;
    }

    private function generateHasManyFunctions($table, $rulesContainer)
    {
        $functions = '';
        foreach ($rulesContainer as $rules) {
            $hasManyModel = $this->generateModelNameFromTableName($rules[0]);
            $key1 = $rules[1];
            $key2 = $rules[2];

            // exclude pivot from relations
            if (!preg_match('/pivot/i', $hasManyModel)) {
                //$hasManyFunctionName = $this->getPluralFunctionName($hasManyModel);

                $name = sprintf('%s_%s', $hasManyModel, preg_replace('/_id/', '', $key1));
                $hasManyFunctionName = camel_case($this->getSingularFunctionName($name));

                $this->classMethods[$table][] = $hasManyFunctionName;

                $function = "
    public function $hasManyFunctionName() {".'
        return $this->hasMany'.'(\\'.self::$namespace."\\$hasManyModel::class, '$key1', '$key2');
    }
";
                $functions .= $function;
            }
        }

        return $functions;
    }

    private function generateHasOneFunctions($table, $rulesContainer)
    {
        $functions = '';
        foreach ($rulesContainer as $rules) {
            $hasOneModel = $this->generateModelNameFromTableName($rules[0]);
            $key1 = $rules[1];
            $key2 = $rules[2];

            $hasOneFunctionName = $this->getSingularFunctionName($hasOneModel);
            //$hasOneFunctionName = camel_case($this->getSingularFunctionName(preg_replace('/_id/', '', $key1)));

            $this->classMethods[$table][] = $hasOneFunctionName;

            $function = "
    public function $hasOneFunctionName() {".'
        return $this->hasOne'.'(\\'.self::$namespace."\\$hasOneModel::class, '$key1', '$key2');
    }
";
            $functions .= $function;
        }

        return $functions;
    }

    private function generateBelongsToFunctions($table, $rulesContainer)
    {
        $functions = '';

        $duplicateMethod = [];
        $checkDuplicateMethod = [];
        foreach ($rulesContainer as $rules) {
            $belongsToModel = $this->generateModelNameFromTableName($rules[0]);
            $name = $this->getSingularFunctionName($belongsToModel);

            if (in_array($name, $checkDuplicateMethod)) {
                $duplicateMethod[] = $name;
            }

            $checkDuplicateMethod[] = $name;
        }

        foreach ($rulesContainer as $rules) {
            $belongsToModel = $this->generateModelNameFromTableName($rules[0]);

            $key1 = $rules[1];
            $key2 = $rules[2];

            $belongsToFunctionName = $this->getSingularFunctionName($belongsToModel);

            if (in_array($belongsToFunctionName, $duplicateMethod)) {
                $name = sprintf('%s_%s', $belongsToModel, preg_replace('/_id/', '', $key1));
                $belongsToFunctionName = camel_case($this->getSingularFunctionName($name));
            }

            //$this->classMethods[$table][] = $belongsToFunctionName;

            $function = "
    public function $belongsToFunctionName() {".'
        return $this->belongsTo'.'(\\'.self::$namespace."\\$belongsToModel::class, '$key1', '$key2');
    }
";
            $functions .= $function;
        }

        return $functions;
    }

    private function generateBelongsToManyFunctions($table, $rulesContainer)
    {
        $functions = '';
        foreach ($rulesContainer as $rules) {
            $belongsToManyModel = $this->generateModelNameFromTableName($rules[0]);
            $through = $rules[1];
            $key1 = $rules[2];
            $key2 = $rules[3];

            //$belongsToManyFunctionName = $this->getPluralFunctionName($belongsToManyModel);
            //$name = sprintf('%s_%s', $belongsToManyModel, preg_replace('/_id/', '', $key2));
            $name = sprintf('%s_%s', preg_replace('/_id/', '', $key1), preg_replace('/_id/', '', $key2));
            $belongsToManyFunctionName = camel_case($this->getPluralFunctionName($name));

            //$this->classMethods[$table][] = $belongsToManyFunctionName;

            $function = "
    public function $belongsToManyFunctionName() {".'
        return $this->belongsToMany'.'(\\'.self::$namespace."\\$belongsToManyModel::class, '$through', '$key1', '$key2');
    }
";
            $functions .= $function;
        }

        return $functions;
    }

    private function getPluralFunctionName($modelName)
    {
        $modelName = lcfirst($modelName);

        return sprintf('%sCollection', $modelName);

        return str_plural($modelName);
    }

    private function getSingularFunctionName($modelName)
    {
        $modelName = lcfirst($modelName);

        return $modelName;

        //return str_singular($modelName);
    }

    private function generateModelNameFromTableName($table)
    {
        return ucfirst(camel_case($table));

        //return ucfirst(camel_case(str_singular($table)));
    }

    private function getColumnsPrimaryAndForeignKeysPerTable($tables)
    {
        $prep = [];
        foreach ($tables as $table) {
            //get foreign keys
            $foreignKeys = $this->schemaGenerator->getForeignKeyConstraints($table);

            //get primary keys
            $primaryKeys = $this->schemaGenerator->getPrimaryKeys($table);

            // get columns lists
            $__columns = $this->schemaGenerator->getSchema()->listTableColumns($table);

            $columns = [];
            foreach ($__columns as $col) {
                $columns[] = $col->toArray()['name'];
            }

            $prep[$table] = [
                'foreign' => $foreignKeys,
                'primary' => $primaryKeys,
                'columns' => $columns,
            ];
        }

        return $prep;
    }

    private function getEloquentRules($tables, $prep)
    {
        $rules = [];

        //first create empty ruleset for each table
        foreach ($prep as $table => $properties) {
            $rules[$table] = [
                'hasMany' => [],
                'hasOne' => [],
                'belongsTo' => [],
                'belongsToMany' => [],
                'fillable' => [],
            ];
        }

        foreach ($prep as $table => $properties) {
            $foreign = $properties['foreign'];
            $primary = $properties['primary'];
            $columns = $properties['columns'];

            $this->setFillableProperties($table, $rules, $columns, $primary);

            $isManyToMany = $this->detectManyToMany($prep, $table);

            if ($isManyToMany === true) {
                $this->addManyToManyRules($tables, $table, $prep, $rules);
            }

            //the below used to be in an ELSE clause but we should be as verbose as possible
            //when we detect a many-to-many table, we still want to set relations on it
            //else
            {
                foreach ($foreign as $fk) {
                    $isOneToOne = $this->detectOneToOne($fk, $primary);

                    if ($isOneToOne) {
                        $this->addOneToOneRules($tables, $table, $rules, $fk);
                    } else {
                        $this->addOneToManyRules($tables, $table, $rules, $fk);
                    }
                }
            }
        }

        return $rules;
    }

    private function setFillableProperties($table, &$rules, $columns, $primary)
    {
        $this->softDelete[$table] = false;
        $this->timestamps[$table] = 'false';
        $fillable = [];
        foreach ($columns as $column_name) {
            if ($column_name !== 'created_at' && $column_name !== 'updated_at' && $column_name !== 'deleted_at') {
                $this->timestamp = 'true';
            }
            if ($column_name === 'deleted_at') {
                $this->softDelete[$table] = true;
            }
			if($column_name != 'id') {
            	$fillable[] = "'$column_name'";
       		}
        }
        if (in_array('created_at', $columns) && in_array('updated_at', $columns)) {
            $this->timestamps[$table] = 'true';
        }
        $rules[$table]['fillable'] = $fillable;
    }

    private function addOneToManyRules($tables, $table, &$rules, $fk)
    {
        //$table belongs to $FK
        //FK hasMany $table

        $fkTable = $fk['on'];
        $field = $fk['field'];
        $references = $fk['references'];
        if (in_array($fkTable, $tables)) {
            $rules[$fkTable]['hasMany'][] = [$table, $field, $references];
        }
        if (in_array($table, $tables)) {
            $rules[$table]['belongsTo'][] = [$fkTable, $field, $references];
        }
    }

    private function addOneToOneRules($tables, $table, &$rules, $fk)
    {
        //$table belongsTo $FK
        //$FK hasOne $table

        $fkTable = $fk['on'];
        $field = $fk['field'];
        $references = $fk['references'];
        if (in_array($fkTable, $tables)) {
            $rules[$fkTable]['hasOne'][] = [$table, $field, $references];
        }
        if (in_array($table, $tables)) {
            $rules[$table]['belongsTo'][] = [$fkTable, $field, $references];
        }
    }

    private function addManyToManyRules($tables, $table, $prep, &$rules)
    {

        //$FK1 belongsToMany $FK2
        //$FK2 belongsToMany $FK1

        $foreign = $prep[$table]['foreign'];

        $fk1 = $foreign[0];
        $fk1Table = $fk1['on'];
        $fk1Field = $fk1['field'];
        //$fk1References = $fk1['references'];

        $fk2 = $foreign[1];
        $fk2Table = $fk2['on'];
        $fk2Field = $fk2['field'];
        //$fk2References = $fk2['references'];

        //User belongstomany groups user_group, user_id, group_id
        if (in_array($fk1Table, $tables)) {
            $rules[$fk1Table]['belongsToMany'][] = [$fk2Table, $table, $fk1Field, $fk2Field];
        }
        if (in_array($fk2Table, $tables)) {
            $rules[$fk2Table]['belongsToMany'][] = [$fk1Table, $table, $fk2Field, $fk1Field];
        }
    }

    //if FK is also a primary key, and there is only one primary key, we know this will be a one to one relationship
    private function detectOneToOne($fk, $primary)
    {
        //echo "--- debug\n";
        //echo 'count($primary): '.count($primary)."\n";
        if (count($primary) === 1) {
            foreach ($primary as $prim) {
                //echo 'test: '.$prim.' === '.$fk['field']."\n";
                //return true;
                if ($prim === $fk['field']) {
                    return true;
                }
            }
        }

        return false;
    }

    //does this table have exactly two foreign keys that are also NOT primary,
    //and no tables in the database refer to this table?
    private function detectManyToMany($prep, $table)
    {
        $properties = $prep[$table];
        $foreignKeys = $properties['foreign'];
        $primaryKeys = $properties['primary'];

        //ensure we only have two foreign keys
        if (count($foreignKeys) === 2) {

            //ensure our foreign keys are not also defined as primary keys
            $primaryKeyCountThatAreAlsoForeignKeys = 0;
            foreach ($foreignKeys as $foreign) {
                foreach ($primaryKeys as $primary) {
                    if ($primary === $foreign['name']) {
                        ++$primaryKeyCountThatAreAlsoForeignKeys;
                    }
                }
            }

            if ($primaryKeyCountThatAreAlsoForeignKeys === 1) {
                //one of the keys foreign keys was also a primary key
                //this is not a many to many. (many to many is only possible when both or none of the foreign keys are also primary)
                return false;
            }

            //ensure no other tables refer to this one
            foreach ($prep as $compareTable => $properties) {
                if ($table !== $compareTable) {
                    foreach ($properties['foreign'] as $prop) {
                        if ($prop['on'] === $table) {
                            return false;
                        }
                    }
                }
            }
            //this is a many to many table!
            return true;
        }

        return false;
    }

    private function initializeSchemaGenerator()
    {
        $this->schemaGenerator = new SchemaGenerator(
            $this->option('connection'),
            null,
            null
        );

        return $this->schemaGenerator;
    }

    /**
     * Fetch the template data.
     *
     * @return array
     */
    protected function getTemplateData()
    {
        return [
            'NAME' => ucwords($this->argument('modelName')),
            'NAMESPACE' => env('APP_NAME', 'App\Models'),
        ];
    }

    /**
     * The path to where the file will be created.
     *
     * @return mixed
     */
    protected function getFileGenerationPath()
    {
        $path = $this->getPathByOptionOrConfig('path', 'model_target_path');

        if (!is_dir($path)) {
            $this->warn('Path is not a directory, creating '.$path);
            mkdir($path);
        }

        return $path;
    }

    /**
     * Get the path to the generator template.
     *
     * @return mixed
     */
    protected function getTemplatePath()
    {
        $tp = __DIR__.'/templates/model.txt';

        return $tp;
    }
}
