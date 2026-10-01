<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes\Feed_Writers
 */

declare(strict_types=1);

namespace AdTribes\PFP\Classes\Feed_Writers;

defined( 'ABSPATH' ) || exit;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Traits\Singleton_Trait;
use codename\parquet\CompressionMethod;
use codename\parquet\ParquetReader;
use codename\parquet\ParquetWriter;
use codename\parquet\data\DataField;
use codename\parquet\data\ListField;
use codename\parquet\data\Schema;
use codename\parquet\data\StructField;
use codename\parquet\helper\ArrayToDataColumnsConverter;

/**
 * Parquet Feed Writer class.
 *
 * Writes product feeds as Apache Parquet, the format the OpenAI Commerce file-upload
 * spec prefers. Parquet is a columnar binary container, not a text stream, so this
 * writer cannot append lines the way Feed_Writer_CSV and Feed_Writer_JSONL do. It
 * instead writes one row group per feed batch and rewrites the file footer each time,
 * which keeps memory flat across a large catalogue.
 *
 * The schema is fixed for the whole run. Parquet stores column names and types in the
 * footer, and the underlying writer refuses to append a row group whose schema differs
 * from the one already in the file, so the column list is resolved once on the first
 * batch and stored against the feed for every batch that follows.
 *
 * @since 13.5.8
 */
class Feed_Writer_Parquet extends Abstract_Class {

    use Singleton_Trait;

    /**
     * PHP extensions the Parquet library needs.
     *
     * These are checked at runtime rather than trusted, because the plugin ships its
     * vendor directory and is installed on hosts that never ran Composer's platform
     * check. zlib provides the GZIP codec the row groups are written with, and bcmath
     * backs the library's arbitrary-precision arithmetic.
     *
     * The codename/parquet package also declares ext-gmp, which is deliberately not
     * required here. Its only use in the package is the DECIMAL logical type
     * (values/primitives/BigDecimal.php), and this writer emits BOOLEAN, UTF8 and
     * nested groups only, so that code is never reached. Requiring gmp would hide the
     * format on the many hosts that omit it for no gain.
     *
     * @since 13.5.8
     *
     * @var string[]
     */
    const REQUIRED_EXTENSIONS = array( 'bcmath', 'zlib' );

    /**
     * Lowest PHP version the Parquet library can run on.
     *
     * The bundled brick/math dependency declares typed properties, which PHP 7.3 cannot
     * parse. A parse error is fatal and uncatchable, so the version is checked before
     * the autoloader is touched rather than left to fail at load time. WooCommerce
     * already requires PHP 7.4, so this only guards a host that somehow runs the plugin
     * below that floor.
     *
     * @since 13.5.8
     *
     * @var string
     */
    const MIN_PHP_VERSION = '7.4';

    /**
     * Sentinel returned by count_rows() when the reader threw while parsing the file.
     *
     * This one condemns the file. It is the only negative result that entitles a caller
     * to delete the file it asked about.
     *
     * @since 13.5.8
     *
     * @var int
     */
    const ROWS_CORRUPT = -1;

    /**
     * Sentinel returned by count_rows() when the count could not be attempted at all.
     *
     * An unsupported host, a library that would not load, or a file that could not be
     * opened. This says nothing about the file's contents, so a caller must not treat it
     * as evidence of damage.
     *
     * @since 13.5.8
     *
     * @var int
     */
    const ROWS_UNKNOWN = -2;

    /**
     * Get the post meta key holding the resolved column list for the current run.
     *
     * Built at call time rather than declared as a constant, because a constant
     * expression referencing Product_Feed would make this file impossible to load
     * before that class, and the plugin autoloads both on demand.
     *
     * @since 13.5.8
     *
     * @return string
     */
    private static function get_columns_meta_key(): string {
        return Product_Feed::META_PREFIX . 'parquet_columns';
    }

    /**
     * Whether the Composer autoloader has been required.
     *
     * @since 13.5.8
     *
     * @var bool
     */
    private static $autoloader_loaded = false;

    /**
     * Whether this host runs a PHP version the Parquet library can load.
     *
     * @since 13.5.8
     *
     * @return bool
     */
    private static function is_php_supported(): bool {
        return version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '>=' );
    }

    /**
     * Get the PHP extensions this host is missing.
     *
     * @since 13.5.8
     *
     * @return string[] Names of the missing extensions. Empty when all are present.
     */
    public static function get_missing_extensions(): array {
        $missing = array();

        foreach ( self::REQUIRED_EXTENSIONS as $extension ) {
            if ( ! extension_loaded( $extension ) ) {
                $missing[] = $extension;
            }
        }

        return $missing;
    }

    /**
     * Load the Composer autoloader that provides the Parquet library.
     *
     * The autoloader is required here rather than in bootstrap/app.php so that a
     * front-end request never pays for it. Note that WP_Admin::enqueue_scripts() and the
     * General tab both call is_supported(), which calls this - so every plugin admin page
     * does load the autoloader. Only a request that writes a feed loads the rest of the
     * library.
     *
     * @since 13.5.8
     *
     * @return bool True when the library is loadable.
     */
    public static function load_library(): bool {
        if ( class_exists( ParquetWriter::class ) ) {
            return true;
        }

        // Above the require, not below it: a parse error in the library is fatal and
        // cannot be caught, so an unsupported PHP version must never reach the
        // autoloader.
        if ( ! self::is_php_supported() ) {
            return false;
        }

        if ( self::$autoloader_loaded ) {
            return false;
        }

        self::$autoloader_loaded = true;

        $autoloader = ADT_PFP_PLUGIN_DIR_PATH . 'vendor/autoload.php';

        if ( ! is_readable( $autoloader ) ) {
            return false;
        }

        require_once $autoloader;

        return class_exists( ParquetWriter::class );
    }

    /**
     * Whether this host can write Parquet files.
     *
     * @since 13.5.8
     *
     * @return bool
     */
    public static function is_supported(): bool {
        /**
         * Filter whether the Parquet file format is offered and written.
         *
         * @since 13.5.8
         *
         * @param bool $supported Whether Parquet is supported on this host.
         */
        return (bool) apply_filters(
            'adt_product_feed_parquet_supported',
            empty( self::get_missing_extensions() ) && self::load_library()
        );
    }

    /**
     * Whether this host can actually run the Parquet library right now.
     *
     * Both checks are needed. load_library() is called explicitly, not left to
     * is_supported(): that method short-circuits on the extension check, so a site that
     * forces support on through the `adt_product_feed_parquet_supported` filter would
     * otherwise reach the library with nothing loaded. Do not collapse this into one
     * is_supported() call.
     *
     * @since 13.5.8
     *
     * @return bool
     */
    private static function is_library_available(): bool {
        return self::is_supported() && self::load_library();
    }

    /**
     * Get a translated sentence explaining why Parquet is unavailable.
     *
     * @since 13.5.8
     *
     * @return string Empty string when Parquet is supported.
     */
    public static function get_unsupported_reason(): string {
        if ( self::is_supported() ) {
            return '';
        }

        if ( ! self::is_php_supported() ) {
            return sprintf(
                /* translators: 1: minimum PHP version, 2: the PHP version this server runs. */
                __( 'PARQUET is unavailable: it needs PHP %1$s or later and this server runs PHP %2$s. Ask your host to update PHP.', 'woo-product-feed-pro' ),
                self::MIN_PHP_VERSION,
                PHP_VERSION
            );
        }

        $missing = self::get_missing_extensions();

        if ( ! empty( $missing ) ) {
            return sprintf(
                /* translators: %s: comma-separated list of PHP extension names. */
                __( 'PARQUET is unavailable: this server is missing the PHP extension(s) %s. Ask your host to enable them.', 'woo-product-feed-pro' ),
                implode( ', ', $missing )
            );
        }

        return __( 'PARQUET is unavailable: the Parquet library that ships with this plugin could not be loaded. Reinstall the plugin, or contact support.', 'woo-product-feed-pro' );
    }

    /**
     * Write a batch of products to the feed's Parquet temp file.
     *
     * @since 13.5.8
     *
     * @param array  $products  Array of product data arrays.
     * @param object $feed      Feed configuration object.
     * @param bool   $is_header Whether this is the header/initialization call.
     * @return string External file URL, or an empty string on failure.
     */
    public function write_feed( array $products, object $feed, bool $is_header ): string {
        try {
            $local_file    = $feed->get_temp_file_path();
            $external_file = $feed->get_file_url();
            $path          = $feed->get_file_dir_path();

            if ( ! file_exists( $path ) ) {
                wp_mkdir_p( $path );
            }

            // A new run starts a new file, and with it a new schema.
            if ( $is_header && $feed->is_first_write_of_run() ) {
                if ( file_exists( $local_file ) ) {
                    wp_delete_file( $local_file );
                }
                delete_post_meta( $feed->id, self::get_columns_meta_key() );
            }

            if ( $is_header || empty( $products ) ) {
                return $external_file;
            }

            if ( ! self::is_library_available() ) {
                $this->log_error( 'Cannot write Parquet feed on this host', array( 'reason' => self::get_unsupported_reason() ) );
                return '';
            }

            // A second concurrent run of the same feed re-claims the temp file: it deletes
            // the file in flight and the stored column list, and makes this run treat its
            // next batch as a fresh header. JSONL survives that because it appends
            // independent text lines, but a Parquet batch appends a row group and rewrites
            // the footer, so an interleave leaves a file no reader can open. Refuse the
            // batch instead: the run fails loudly, the previous feed stays live, and the
            // move-time guard keeps an unreadable file from being published.
            if ( ! $this->run_owns_temp_file( $feed ) ) {
                $this->log_error(
                    'Refusing to append a Parquet row group: another run has claimed this feed temp file',
                    array( 'feed_id' => $feed->id )
                );
                return '';
            }

            $columns = $this->resolve_columns( $products, $feed );

            if ( empty( $columns ) ) {
                $this->log_error( 'Parquet feed has no columns to write', array( 'feed_id' => $feed->id ) );
                return '';
            }

            $types  = $this->get_column_types( $feed );
            $schema = $this->build_schema( $columns, $types );
            $rows   = $this->normalize_rows( $products, $columns, $types );

            if ( empty( $rows ) ) {
                return $external_file;
            }

            $this->write_row_group( $local_file, $schema, $rows );

            return $external_file;

        } catch ( \Throwable $e ) {
            $this->log_error( 'Exception during Parquet feed writing', array( 'message' => $e->getMessage() ) );
            return '';
        }
    }

    /**
     * Whether the current run still owns the feed's temp file.
     *
     * Product_Feed::is_first_write_of_run() stamps the temp file with the run that
     * claimed it. A run whose stamp a newer run has taken over must stop appending.
     *
     * A run with no ID predates the stamp (see is_first_write_of_run), so there is
     * nothing to compare and the batch proceeds as it did before.
     *
     * @since 13.5.8
     *
     * @param object $feed Feed configuration object.
     * @return bool
     */
    private function run_owns_temp_file( object $feed ): bool {
        $run_id = (string) $feed->batch_run_id;

        if ( '' === $run_id ) {
            return true;
        }

        $owner_run_id = (string) get_post_meta( $feed->id, Product_Feed::META_PREFIX . 'temp_file_run_id', true );

        return $owner_run_id === $run_id;
    }

    /**
     * Write one row group, creating the file on the first batch and appending after that.
     *
     * The file is opened 'r+' to append so the existing footer is read back and the new
     * row group is inserted before a rewritten footer. Opening 'w+' would truncate it.
     *
     * @since 13.5.8
     *
     * @param string $local_file Absolute path of the temp file.
     * @param Schema $schema     The Parquet schema.
     * @param array  $rows       Normalized row arrays.
     * @return void
     * @throws \Exception When the file cannot be opened.
     */
    private function write_row_group( string $local_file, Schema $schema, array $rows ): void {
        // An existing file already carries a footer and a schema, so this batch appends
        // to it. The smallest possible Parquet file is 12 bytes - "PAR1", a 4-byte footer
        // length, and the trailing "PAR1" - so anything at or below that holds no footer
        // to append to and must be started fresh.
        $append = file_exists( $local_file ) && filesize( $local_file ) > 12;

        $handle = fopen( $local_file, $append ? 'r+b' : 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

        if ( false === $handle ) {
            throw new \Exception( 'Failed to open Parquet file for writing' );
        }

        try {
            $writer                    = new ParquetWriter( $schema, $handle, null, $append );
            $writer->compressionMethod = CompressionMethod::Gzip;

            $columns   = ( new ArrayToDataColumnsConverter( $schema, $rows ) )->toDataColumns();
            $row_group = $writer->CreateRowGroup();

            foreach ( $columns as $column ) {
                $row_group->WriteColumn( $column );
            }

            // Both calls are needed and safe in this order: the row group's finish() is
            // idempotent, and the writer's finish() sums the row counts of every group it
            // opened into the footer it then rewrites.
            $row_group->finish();
            $writer->finish();
        } finally {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }
    }

    /**
     * Resolve the column list for this run, storing it on the first batch.
     *
     * The merchant's field mapping is the authoritative column list: it is complete and
     * does not vary with product data, unlike the keys of any one batch, which are absent
     * for an empty value. Keys the channel adds on top of the mapping (OpenAI injects its
     * required-field defaults, for instance) are unioned in from the first batch.
     *
     * @since 13.5.8
     *
     * @param array  $products Array of product data arrays.
     * @param object $feed     Feed configuration object.
     * @return string[] Ordered column names.
     */
    private function resolve_columns( array $products, object $feed ): array {
        $stored = get_post_meta( $feed->id, self::get_columns_meta_key(), true );

        if ( is_array( $stored ) && ! empty( $stored ) ) {
            return $stored;
        }

        $columns = array();

        foreach ( $this->get_mapped_attributes( $feed ) as $attribute ) {
            $columns[ $attribute ] = true;
        }

        foreach ( $products as $product ) {
            if ( ! is_array( $product ) ) {
                continue;
            }

            foreach ( array_keys( $product ) as $key ) {
                $columns[ (string) $key ] = true;
            }
        }

        $columns = array_keys( $columns );

        /**
         * Filter the Parquet column list resolved for a feed run.
         *
         * @since 13.5.8
         *
         * @param string[] $columns Ordered column names.
         * @param object   $feed    The product feed instance.
         */
        $columns = (array) apply_filters( 'adt_product_feed_parquet_columns', $columns, $feed );

        update_post_meta( $feed->id, self::get_columns_meta_key(), $columns );

        return $columns;
    }

    /**
     * Get the output attribute names of the feed's field mapping.
     *
     * Mirrors the two key rewrites the legacy generator applies to the assembled product
     * array (see Get_Products), so a mapped name and its column name cannot diverge.
     *
     * @since 13.5.8
     *
     * @param object $feed Feed configuration object.
     * @return string[]
     */
    private function get_mapped_attributes( object $feed ): array {
        /** This filter is documented in classes/class-get-products.php */
        $attributes = (array) apply_filters( 'adt_feed_get_attributes', $feed->attributes );
        $names      = array();

        foreach ( $attributes as $attribute ) {
            if ( ! is_array( $attribute ) || empty( $attribute['attribute'] ) ) {
                continue;
            }

            $names[] = Product_Feed_Helper::strip_mapped_attribute_prefix( $attribute['attribute'] );
        }

        return $names;
    }

    /**
     * Get the column type map for a feed.
     *
     * Every column is a UTF8 string unless a channel declares otherwise. Feed values
     * reach this writer as strings, and the OpenAI spec's own price fields carry their
     * currency ("12.60 AUD"), so string is the correct default rather than a fallback.
     *
     * @since 13.5.8
     *
     * @param object $feed Feed configuration object.
     * @return array<string,array> Column name => type declaration.
     */
    private function get_column_types( object $feed ): array {
        /**
         * Filter the Parquet column types for a feed.
         *
         * Each entry is keyed by column name and declares a type. Supported shapes:
         *
         *   array( 'type' => 'string' )   a UTF8 column (the default for any column
         *                                 with no entry).
         *   array( 'type' => 'boolean' )  a BOOLEAN column.
         *   array( 'type' => 'list', 'item' => <declaration> )
         *                                 a repeated column of the item declaration.
         *   array( 'type' => 'struct', 'fields' => array( <name> => <declaration> ) )
         *                                 a group column.
         *
         * @since 13.5.8
         *
         * @param array  $types Column name => type declaration.
         * @param object $feed  The product feed instance.
         */
        return (array) apply_filters( 'adt_product_feed_parquet_column_types', array(), $feed );
    }

    /**
     * Build the Parquet schema from the column list and type map.
     *
     * @since 13.5.8
     *
     * @param string[] $columns Ordered column names.
     * @param array    $types   Column name => type declaration.
     * @return Schema
     */
    private function build_schema( array $columns, array $types ): Schema {
        $fields = array();

        foreach ( $columns as $column ) {
            $fields[] = $this->build_field( $column, $types[ $column ] ?? array( 'type' => 'string' ) );
        }

        return new Schema( $fields );
    }

    /**
     * Build one schema field from a type declaration.
     *
     * Every field is nullable. A feed value is absent far more often than not — the
     * merchant maps a field the catalogue only partly fills — and a required Parquet
     * field cannot carry a null.
     *
     * @since 13.5.8
     *
     * @param string $name        Field name.
     * @param array  $declaration Type declaration.
     * @return \codename\parquet\data\Field
     */
    private function build_field( string $name, array $declaration ) {
        $type = $declaration['type'] ?? 'string';

        if ( 'list' === $type ) {
            return new ListField(
                $name,
                $this->build_field( 'element', (array) ( $declaration['item'] ?? array( 'type' => 'string' ) ) ),
                true
            );
        }

        if ( 'struct' === $type ) {
            $fields = array();

            foreach ( (array) ( $declaration['fields'] ?? array() ) as $field_name => $field_declaration ) {
                $fields[] = $this->build_field( (string) $field_name, (array) $field_declaration );
            }

            return StructField::createWithFieldArray( $name, $fields, true );
        }

        return DataField::createFromType( $name, 'boolean' === $type ? 'boolean' : 'string' );
    }

    /**
     * Shape every product row to the schema.
     *
     * The columnar converter needs one value per column per row, in schema order, so a
     * key the row does not carry becomes an explicit null and a key the schema does not
     * carry is dropped. Dropping only happens for a column the field mapping gained
     * mid-run, which cannot be added to a file whose footer is already written.
     *
     * Note that this deliberately does NOT collapse repeatable attributes (tag/tag_2/...)
     * into arrays the way Feed_Writer_JSONL does through Repeatable_Attributes. Those
     * repeats belong to Google Shopping, and Parquet is offered on the OpenAI channel
     * only, which declares none - so collapsing here would fold real columns together for
     * no consumer, and the column types a channel declares would no longer match the
     * shapes written. Revisit this only if a channel with repeatables ever accepts
     * Parquet, and change the type map with it.
     *
     * @since 13.5.8
     *
     * @param array    $products Array of product data arrays.
     * @param string[] $columns  Ordered column names.
     * @param array    $types    Column name => type declaration.
     * @return array Normalized rows.
     */
    private function normalize_rows( array $products, array $columns, array $types ): array {
        $rows = array();

        foreach ( $products as $product ) {
            if ( empty( $product ) || ! is_array( $product ) ) {
                continue;
            }

            $row = array();

            foreach ( $columns as $column ) {
                $row[ $column ] = $this->normalize_value(
                    $product[ $column ] ?? null,
                    $types[ $column ] ?? array( 'type' => 'string' )
                );
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Cast one value to the PHP shape the declared Parquet type expects.
     *
     * @since 13.5.8
     *
     * @param mixed $value       The value to cast.
     * @param array $declaration Type declaration.
     * @return mixed
     */
    private function normalize_value( $value, array $declaration ) {
        $type = $declaration['type'] ?? 'string';

        if ( 'list' === $type ) {
            if ( ! is_array( $value ) ) {
                // A scalar is a single-element list; anything empty is a null list, which
                // Parquet keeps distinct from the empty list an empty array would write.
                return ( null === $value || '' === $value ) ? null : array( $this->normalize_value( $value, (array) ( $declaration['item'] ?? array() ) ) );
            }

            $item = (array) ( $declaration['item'] ?? array( 'type' => 'string' ) );

            return array_map(
                function ( $entry ) use ( $item ) {
                    return $this->normalize_value( $entry, $item );
                },
                array_values( $value )
            );
        }

        if ( 'struct' === $type ) {
            if ( ! is_array( $value ) ) {
                return null;
            }

            $struct = array();

            // Every declared key must be present: the converter reads the group's fields
            // by name, and OpenAI's shipping entries omit 'region' when it is empty.
            foreach ( (array) ( $declaration['fields'] ?? array() ) as $field_name => $field_declaration ) {
                $struct[ $field_name ] = $this->normalize_value( $value[ $field_name ] ?? null, (array) $field_declaration );
            }

            return $struct;
        }

        if ( 'boolean' === $type ) {
            if ( is_bool( $value ) ) {
                return $value;
            }

            if ( null === $value || '' === $value ) {
                return null;
            }

            return in_array( strtolower( trim( (string) $value ) ), array( 'true', '1', 'yes' ), true );
        }

        if ( null === $value || is_array( $value ) ) {
            // An array reaching a string column means a channel produced structured data
            // for a column it never declared a type for. Encoding it keeps the value
            // readable instead of writing a PHP "Array" notice into the feed.
            return null === $value ? null : wp_json_encode( $value );
        }

        if ( is_bool( $value ) ) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * Count the rows a finished Parquet file holds.
     *
     * Reads the footer only, so the cost does not scale with the catalogue.
     *
     * @since 13.5.8
     *
     * A readable file that genuinely holds no rows returns 0. A negative result means no
     * count was taken, and the two negatives are not interchangeable:
     *
     *   ROWS_CORRUPT (-1) the reader opened the file and threw parsing it. The file is
     *                     damaged, and a caller may delete it.
     *   ROWS_UNKNOWN (-2) the count could not be attempted - an unsupported host, a
     *                     library that would not load, or a file that would not open.
     *                     This says nothing about the file, so a caller must not delete
     *                     it on the strength of it.
     *
     * `Product_Feed::move_feed_file_to_final()` and `Cron` both rely on that distinction:
     * conflating the two would discard a perfectly good temp file whenever the library
     * became unloadable, or the `adt_product_feed_parquet_supported` filter flipped
     * between the write and the move.
     *
     * @param string $file Absolute path of the Parquet file.
     * @return int Row count, ROWS_CORRUPT, or ROWS_UNKNOWN.
     */
    public static function count_rows( string $file ): int {
        if ( ! self::is_library_available() || ! is_readable( $file ) ) {
            return self::ROWS_UNKNOWN;
        }

        $handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

        if ( false === $handle ) {
            return self::ROWS_UNKNOWN;
        }

        try {
            $reader = new ParquetReader( $handle );

            // getRowCount() sums the row-group counts instead of trusting the footer's
            // num_rows, which the library documents as unreliable.
            return $reader->getRowCount();
        } catch ( \Throwable $e ) {
            return self::ROWS_CORRUPT;
        } finally {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }
    }

    /**
     * Log error message using WordPress logging.
     *
     * @since 13.5.8
     *
     * @param string $message Error message.
     * @param array  $context Additional context data.
     * @return void
     */
    private function log_error( string $message, array $context = array() ): void {
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->error(
                $message,
                array_merge( array( 'source' => 'woo-product-feed-pro-parquet' ), $context )
            );
        }

        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( 'Parquet Feed Writer: ' . $message . ' ' . wp_json_encode( $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    /**
     * Run the class.
     *
     * @since 13.5.8
     */
    public function run() {
        // No hooks needed for this writer class.
        // It is called directly from the legacy feed generation code.
    }
}
