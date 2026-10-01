<?php
namespace codename\parquet\data\concrete;

use codename\parquet\data\DataType;
use codename\parquet\data\BasicDataTypeHandler;
use codename\parquet\data\BasicPrimitiveDataTypeHandler;

use codename\parquet\format\Type;
use codename\parquet\format\ConvertedType;

class Int32DataTypeHandler extends BasicPrimitiveDataTypeHandler
{
  /**
   */
  public function __construct()
  {
    $this->phpType = 'integer';
    parent::__construct(DataType::Int32, Type::INT32);
  }

  /**
   * Maximum value for datatype
   * @var int
   */
  const MaxValue = 2147483647;

  /**
   * Minimum value for datatype
   * @var int
   */
  const MinValue = -2147483648;

  /**
   * @inheritDoc
   */
  protected function readSingle(
    \codename\parquet\adapter\BinaryReader $reader,
    \codename\parquet\format\SchemaElement $tse,
    int $length
  ) {
    return $reader->readInt32();
  }

  /**
   * @inheritDoc
   */
  protected function WriteOne(\codename\parquet\adapter\BinaryWriter $writer, $value): void
  {
    //
    // As we're dealing with integers in PHP
    // we have to perform this extra-check to keep it inside the bounds
    //
    if((static::MinValue <= $value) && ($value <= static::MaxValue)) {
      $writer->writeInt32($value);
    } else {
      throw new \Exception('Value out of Int32 bounds');
    }
  }
}
