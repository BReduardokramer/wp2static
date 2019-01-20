<?php

class StaticHtmlOutput_S3 extends StaticHtmlOutput_SitePublisher {

    public function __construct() {
        $this->loadSettings( 's3' );

        $this->previous_hashes_path =
            $this->settings['wp_uploads_path'] .
                '/WP2STATIC-S3-PREVIOUS-HASHES.txt';

        if ( defined( 'WP_CLI' ) ) { return; }

        switch ( $_POST['ajax_action'] ) {
            case 'test_s3':
                $this->test_s3();
                break;
            case 's3_prepare_export':
                $this->bootstrap();
                $this->loadArchive();
                $this->prepareDeploy();
                break;
            case 's3_transfer_files':
                $this->bootstrap();
                $this->loadArchive();
                $this->upload_files();
                break;
            case 'cloudfront_invalidate_all_items':
                $this->cloudfront_invalidate_all_items();
                break;
        }
    }

    public function upload_files() {
        $this->files_remaining = $this->getRemainingItemsCount();

        if ( $this->files_remaining < 0 ) { echo 'ERROR'; die(); }

        $this->initiateProgressIndicator();

        $batch_size = $this->settings['deployBatchSize'];

        if ( $batch_size > $this->files_remaining ) {
            $batch_size = $this->files_remaining;
        }

        $lines = $this->getItemsToDeploy( $batch_size );

        $this->openPreviousHashesFile();

        require_once dirname( __FILE__ ) .
            '/../library/StaticHtmlOutput/MimeTypes.php';

        foreach ( $lines as $line ) {
            list($local_file, $this->target_path) = explode( ',', $line );

            $local_file = $this->archive->path . $local_file;

            if ( ! is_file( $local_file ) ) { continue; }

            if ( isset( $this->settings['s3RemotePath'] ) ) {
                $this->target_path =
                    $this->settings['s3RemotePath'] . '/' . $this->target_path;
            }

            $this->local_file_contents = file_get_contents( $local_file );

            if ( isset( $this->file_paths_and_hashes[ $this->target_path ] ) ) {
                $prev = $this->file_paths_and_hashes[ $this->target_path ];
                $current = crc32( $this->local_file_contents );

                if ( $prev != $current ) {
                    try {
                        $this->put_s3_object(
                            $this->target_path .
                                    basename( $local_file ),
                            $this->local_file_contents,
                            GuessMimeType( $local_file )
                        );

                    } catch ( Exception $e ) {
                        $this->handleException( $e );
                    }
                }
            } else {
                try {
                    $this->put_s3_object(
                        $this->target_path .
                                basename( $local_file ),
                        $this->local_file_contents,
                        GuessMimeType( $local_file )
                    );

                } catch ( Exception $e ) {
                    $this->handleException( $e );
                }
            }

            $this->recordFilePathAndHashInMemory(
                $this->target_path,
                $this->local_file_contents
            );

            $this->updateProgress();
        }

        $this->writeFilePathAndHashesToFile();

        $this->pauseBetweenAPICalls();

        if ( $this->uploadsCompleted() ) {
            $this->finalizeDeployment();
        }
    }

    public function test_s3() {
        try {
            $this->put_s3_object(
                '.tmp_wp2static.txt',
                'Test WP2Static connectivity',
                'text/plain'
            );
        } catch ( Exception $e ) {
            require_once dirname( __FILE__ ) .
                '/../library/StaticHtmlOutput/WsLog.php';

            WsLog::l( 'S3 ERROR RETURNED: ' . $e );
            echo "There was an error testing S3.\n";
        }

        if ( ! defined( 'WP_CLI' ) ) {
            echo 'SUCCESS';
        }
    }

    public function put_s3_object( $s3_path, $content, $content_type ) {
        // AWS file permissions
        $content_acl = 'public-read';

        // MIME type of file. Very important to set if you later plan to load the file from a S3 url in the browser (images, for example)
        // Name of content on S3
        $content_title = $s3_path;

        $host_name = $this->settings['s3Bucket'] . '.s3.amazonaws.com';

        // Service name for S3
        $aws_service_name = 's3';

        // UTC timestamp and date
        $timestamp = gmdate( 'Ymd\THis\Z' );
        $date = gmdate( 'Ymd' );

        // HTTP request headers as key & value
        $request_headers = array();
        $request_headers['Content-Type'] = $content_type;
        $request_headers['Date'] = $timestamp;
        $request_headers['Host'] = $host_name;
        $request_headers['x-amz-acl'] = $content_acl;
        $request_headers['x-amz-content-sha256'] = hash( 'sha256', $content );
        // Sort it in ascending order
        ksort( $request_headers );

        // Canonical headers
        $canonical_headers = [];
        foreach ( $request_headers as $key => $value ) {
            $canonical_headers[] = strtolower( $key ) . ':' . $value;
        }
        $canonical_headers = implode( "\n", $canonical_headers );

        // Signed headers
        $signed_headers = [];
        foreach ( $request_headers as $key => $value ) {
            $signed_headers[] = strtolower( $key );
        }
        $signed_headers = implode( ';', $signed_headers );

        // Cannonical request
        $canonical_request = [];
        $canonical_request[] = 'PUT';
        $canonical_request[] = '/' . $content_title;
        $canonical_request[] = '';
        $canonical_request[] = $canonical_headers;
        $canonical_request[] = '';
        $canonical_request[] = $signed_headers;
        $canonical_request[] = hash( 'sha256', $content );
        $canonical_request = implode( "\n", $canonical_request );
        $hashed_canonical_request = hash( 'sha256', $canonical_request );

        // AWS Scope
        $scope = [];
        $scope[] = $date;
        $scope[] = $this->settings['s3Region'];
        $scope[] = $aws_service_name;
        $scope[] = 'aws4_request';

        // String to sign
        $string_to_sign = [];
        $string_to_sign[] = 'AWS4-HMAC-SHA256';
        $string_to_sign[] = $timestamp;
        $string_to_sign[] = implode( '/', $scope );
        $string_to_sign[] = $hashed_canonical_request;
        $string_to_sign = implode( "\n", $string_to_sign );

        // Signing key
        $kSecret = 'AWS4' . $this->settings['s3Secret'];
        $kDate = hash_hmac( 'sha256', $date, $kSecret, true );
        $kRegion = hash_hmac( 'sha256', $this->settings['s3Region'], $kDate, true );
        $kService = hash_hmac( 'sha256', $aws_service_name, $kRegion, true );
        $kSigning = hash_hmac( 'sha256', 'aws4_request', $kService, true );

        // Signature
        $signature = hash_hmac( 'sha256', $string_to_sign, $kSigning );

        // Authorization
        $authorization = [
            'Credential=' . $this->settings['s3Key'] . '/' . implode( '/', $scope ),
            'SignedHeaders=' . $signed_headers,
            'Signature=' . $signature,
        ];
        $authorization = 'AWS4-HMAC-SHA256' . ' ' . implode( ',', $authorization );

        // Curl headers
        $curl_headers = [ 'Authorization: ' . $authorization ];
        foreach ( $request_headers as $key => $value ) {
            $curl_headers[] = $key . ': ' . $value;
        }

        $url = 'https://' . $host_name . '/' . $content_title;
        $ch = curl_init( $url );
        curl_setopt( $ch, CURLOPT_HEADER, false );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $curl_headers );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
        curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'PUT' );
        curl_setopt( $ch, CURLOPT_USERAGENT, 'WP2Static.com' );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 0 );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 600 );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $content );
        $output = curl_exec( $ch );
        $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        if ( $http_code != 200 ) {
            exit( 'Error : Failed to upload' );
        }
        curl_close( $ch );
    }

    public function cloudfront_invalidate_all_items() {
        if ( ! isset( $this->settings['cfDistributionId'] ) ) {
            if ( ! defined( 'WP_CLI' ) ) { echo 'SUCCESS'; }

            return;
        }

        require_once __DIR__ . '/../library/CloudFront/CloudFront.php';
        $cloudfront_id = $this->settings['cfDistributionId'];

        if ( ! empty( $cloudfront_id ) ) {

            $cf = new CloudFront(
                $this->settings['s3Key'],
                $this->settings['s3Secret'],
                $cloudfront_id
            );

            $cf->invalidate( '/*' );

            if ( $cf->getResponseMessage() === '200' ||
                $cf->getResponseMessage() === '201' ||
                $cf->getResponseMessage() === '201: Request accepted' ) {

                if ( ! defined( 'WP_CLI' ) ) { echo 'SUCCESS'; }
            } else {
                require_once dirname( __FILE__ ) .
                    '/../library/StaticHtmlOutput/WsLog.php';
                WsLog::l( 'CF ERROR: ' . $cf->getResponseMessage() );
            }
        } else {
            if ( ! defined( 'WP_CLI' ) ) { echo 'SUCCESS'; }
        }
    }
}

$s3 = new StaticHtmlOutput_S3();