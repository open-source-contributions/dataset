<?php

namespace Dataset;

use Illuminate\Database\Eloquent\Model;
use League\Csv\Reader;
use League\Csv\Statement;
use League\Csv\TabularDataReader;
use Throwable;

abstract class CsvStorage
{
    use Support;

    /** @var $reader Reader */
    private $reader = null;

    /**
     * If the document contains any header, position of the header
     */
    protected function headerOffset () : ?int {
        return 0;
    }

    /**
     * If want to include the empty records in the result set
     */
    protected function skipEmptyRecord () : bool {
        return true;
    }

    /**
     * Array of stream filters
     * https://csv.thephpleague.com/9.0/connections/filters/#adding-a-stream-filter
     */
    protected function streamFilters () : array {
        return [];
    }

    /**
     * No of rows to be fetched at a time
     */
    protected function limit () : int {
        return 1000;
    }

    /**
     * If want to execute the insert operations in a transaction
     */
    protected function useTransaction () : bool {
        return false;
    }

    /**
     * Self implementation will make capable of writing Eloquent & DB operations to insert or update row
     */
    protected function entries () : array {
        return [
            $this->table() => function (Model $model, array $record, array $previous) : Model {
                foreach ( $record as $field => $value ) {
                    $model->{$field} = $value;
                }

                $model->save();

                return $model;
            },
        ];
    }

    protected function filterInput (array $record) : array {
        return $record;
    }

    /**
     * If wants to overwrite existing headers or provide headers if not available
     */
    protected function headers () : array {
        return [];
    }

    /**
     * Return new data based on any calculation or mutate data if required
     *
     * @param array $record
     *
     * @return array
     */
    protected function mutation (array $record) : array {
        return [];
    }

    /**
     * Exit insertion if any error occurs
     */
    protected function exitOnError () : bool {
        return true;
    }

    /**
     * Process each record & insert into CSV
     *
     * @param array $record
     *
     * @return array
     */
    private function processRecord (array $record) : bool {
        $record = $this->filterInput(array_merge($record, $this->mutation($record)));

        $previous = [];
        foreach ( $this->entries() as $table => $closure ) {
            try {
                $eloquent = new class ($table) extends Model
                {
                    public function __construct ($table) {
                        parent::__construct([]);
                        $this->table = $table;
                        $this->guarded = [];
                        $this->timestamps = false;
                    }
                };

                $previous[$table] = $closure($eloquent, $record, $previous);
                unset($eloquent);
            } catch ( Throwable $t ) {
                if (true === $this->exitOnError()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Process records in batch
     *
     * @param TabularDataReader $records
     *
     * @param int               $page
     *
     * @return bool
     */
    private function processRecordBatch (TabularDataReader $records, int $page) : bool {
        /*$result = $this->exitOnEventResponse('iteration.batch', [
            'batch' => $page,
            'count' => $records->count(),
            'limit' => $this->limit(),
        ]);
        if (!$result) {
            return false;
        }*/

        foreach ( $records as $record ) {
            if (false === $this->processRecord($record)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Process the entries of the CSV
     */
    private function processSource () : bool {
        /*$result = $this->exitOnEventResponse('iteration.started', [
            'uses'  => 'source',
            'limit' => $this->limit(),
        ]);
        if (!$result) {
            return false;
        }*/

        $shouldBreak = false;
        $limit = $this->limit();
        $page = 1;
        $isCompleted = true;

        do {
            $offset = ($page - 1) * $limit;
            $records = (new Statement())->limit($limit)->offset($offset)->process($this->reader, $this->headers());

            /**
             * if the result count is less than the limit. all the data are pulled
             */
            if ($records->count() < $limit) {
                $shouldBreak = true;
            }

            if (false === $this->processRecordBatch($records, $page++)) {
                /*$this->fireEvent('iteration.stopped', [
                    'uses' => 'cursor',
                ]);*/
                $isCompleted = false;
                break;
            }
        } while ( false === $shouldBreak );

        /*$this->fireEvent('iteration.completed', [
            'uses' => 'cursor',
        ]);*/

        return $isCompleted;
    }

    /**
     * Prepare all the task
     * Import the result set into the database table
     */
    public function import () : bool {
        /*$result = $this->exitOnEventResponse('starting');
        if (!$result) {
            return false;
        }*/

        if (false === $this->prepareReader()) {
            return false;
        }

        if ($this->useTransaction()) {
            $this->db()->beginTransaction();
        }

        $response = $this->processSource();
        if ($this->useTransaction()) {
            $response ? $this->db()->commit() : $this->db()->rollBack();
        }

        return $response;
    }

    /**
     * Instantiate the file reader
     */
    private function prepareReader () : bool {
        /*$result = $this->exitOnEventResponse('reading', [ 'file' => $this->filename() ]);
        if (!$result) {
            return false;
        }*/

        $this->reader = Reader::createFromPath($this->filename(), $this->fileOpenMode());
        $this->reader->setDelimiter($this->delimiterCharacter());
        $this->reader->setEnclosure($this->enclosureCharacter());
        $this->reader->setEscape($this->escapeCharacter());
        $this->reader->setHeaderOffset($this->headerOffset());
        $this->skipEmptyRecord() ? $this->reader->skipEmptyRecords() : $this->reader->includeEmptyRecords();

        if ($this->reader->supportsStreamFilter() && ($filters = $this->streamFilters())) {
            foreach ( $filters as $filter ) {
                $this->reader->addStreamFilter($filter);
            }
        }

        return true;
    }
}