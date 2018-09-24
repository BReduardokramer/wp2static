<?php
/**
 * DeflateStream
 *
 * @package WP2Static
 */

namespace Http\Message\Encoding;

use Psr\Http\Message\StreamInterface;

/**
 * Stream deflate (RFC 1951).
 *
 * @author Joel Wurtz <joel.wurtz@gmail.com>
 */
class DeflateStream extends FilteredStream
{
    /**
     * @param StreamInterface $stream
     * @param int             $level
     */
    public function __construct(StreamInterface $stream, $level = -1)
    {
        parent::__construct($stream, ['window' => -15, 'level' => $level], ['window' => -15]);
    }

    /**
     * {@inheritdoc}
     */
    protected function readFilter()
    {
        return 'zlib.deflate';
    }

    /**
     * {@inheritdoc}
     */
    protected function writeFilter()
    {
        return 'zlib.inflate';
    }
}
