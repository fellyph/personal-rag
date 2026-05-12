<?php
/**
 * Vector encoding and similarity helpers.
 *
 * @package Personal_RAG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles embedding vector conversion and scoring.
 */
class Personal_RAG_Vectors {
	/**
	 * Decodes a base64-encoded float vector.
	 *
	 * @param string $encoded Base64 encoded float32 binary string.
	 * @return array<string,mixed>|WP_Error
	 */
	public function decode_vector( $encoded ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$binary = base64_decode( $encoded, true );
		if ( false === $binary || '' === $binary || 0 !== strlen( $binary ) % 4 ) {
			return new WP_Error(
				'personal_rag_invalid_vector',
				__( 'Vector payload is invalid.', 'personal-rag' ),
				array( 'status' => 400 )
			);
		}

		$values = $this->binary_to_floats( $binary );
		if ( empty( $values ) ) {
			return new WP_Error(
				'personal_rag_empty_vector',
				__( 'Vector payload is empty.', 'personal-rag' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'binary'  => $binary,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'encoded' => base64_encode( $binary ),
			'values'  => $values,
			'norm'    => $this->vector_norm( $values ),
		);
	}

	/**
	 * Converts a stored vector value to floats.
	 *
	 * @param string $stored Stored vector payload.
	 * @return array<int,float>
	 */
	public function stored_vector_to_floats( $stored ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$binary = base64_decode( (string) $stored, true );
		if ( false !== $binary && '' !== $binary && 0 === strlen( $binary ) % 4 ) {
			return $this->binary_to_floats( $binary );
		}

		return $this->binary_to_floats( $stored );
	}

	/**
	 * Converts float32 binary data to PHP floats.
	 *
	 * @param string $binary Binary vector data.
	 * @return array<int,float>
	 */
	public function binary_to_floats( $binary ) {
		$values = unpack( 'f*', $binary );
		return $values ? array_values( $values ) : array();
	}

	/**
	 * Calculates vector norm.
	 *
	 * @param array<int,float> $values Vector values.
	 * @return float
	 */
	public function vector_norm( $values ) {
		$sum = 0.0;
		foreach ( $values as $value ) {
			$sum += (float) $value * (float) $value;
		}

		return sqrt( $sum );
	}

	/**
	 * Calculates cosine similarity for equal-dimension vectors.
	 *
	 * @param array<int,float> $a      First vector.
	 * @param float            $a_norm First vector norm.
	 * @param array<int,float> $b      Second vector.
	 * @param float            $b_norm Second vector norm.
	 * @return float|null
	 */
	public function cosine_similarity( $a, $a_norm, $b, $b_norm ) {
		if ( $a_norm <= 0 || $b_norm <= 0 || count( $a ) !== count( $b ) ) {
			return null;
		}

		$dot = 0.0;
		$n   = count( $a );
		for ( $i = 0; $i < $n; $i++ ) {
			$dot += (float) $a[ $i ] * (float) $b[ $i ];
		}

		return $dot / ( $a_norm * $b_norm );
	}
}
