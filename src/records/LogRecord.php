<?php

namespace justinholtweb\sevvies\records;

use craft\db\ActiveRecord;
use justinholtweb\sevvies\db\Table;

class LogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LOG;
    }
}
