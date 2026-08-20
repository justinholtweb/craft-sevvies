<?php

namespace justinholtweb\sevvies\records;

use craft\db\ActiveRecord;
use justinholtweb\sevvies\db\Table;

class CreditRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::CREDITS;
    }
}
