<?php

class_exists( 'WP_CLI' ) || exit;

class Uploads_Pull_Command extends WP_CLI_Command {

	/**
	 * Get missing uploads
	 *
	 * <remote>
	 * : Remote key alias to get.
	 */
	public function __invoke( $args, $assoc_args ) {
		list( $remote ) = $args;

		$url = $this->url( $remote );
		$url = rtrim( $url, '/' );

		$files = $this->local_attached_files();

		foreach ( $files as $index => $file ) {
			$upload_dir = wp_upload_dir();

			$remote_base_url = preg_replace(
				'#^' . home_url() . '#',
				$url,
				$upload_dir['baseurl']
			);

			$remote_file = $remote_base_url . '/' . $file;
			$local_file  = $upload_dir['basedir'] . '/' . $file;
			if ( file_exists( $local_file ) ) {
				unset( $files[ $index ] );
			} else {
				$this->curl( $remote_file,  $local_file );
			}
		}

		$files = array_values( $files );

		WP_CLI::log( impĺode( "\n", $files ) );
	}

	private function curl( $remote_file, $local_file ) {
		$cmd = WP_CLI\Utils\esc_cmd(
			'curl -s %s -o %s',
			$remote_file,
			$local_file
		);
		WP_CLI::debug( $cmd );
		passthru( $cmd );
	}

	private function get_config( $name ) {
		$configs = array();
		foreach ( WP_CLI::get_configurator()->to_array() as $config ) {
			$configs = array_merge( $configs, $config );
		}
		if ( ! isset( $configs[ $name ] ) ) {
			WP_CLI::error( "The config '$name' is not defined." );
		}
		return $configs[ $name ];
	}

	private function url( $remote ) {
		$alias = WP_CLI::runcommand( 'cli alias get ' . $remote, array( 'return' => true ) );
		preg_match_all( '/([^:]*):\s*(.*)\s*/', $alias, $output );
		$bits = (Object) array_combine( $output[1], $output[2] );
		if ( ! isset( $bits->url ) ) {
			WP_CLI::error( sprintf( "URL not defined in alias '%s'.", $remote ) );
		}
		return $bits->url;
	}

	private function local_attached_files() {
		$wp = WP_CLI::runcommand( 'config get table_prefix', array( 'return' => true ) );
		WP_CLI::success( $wp );
		$path = '';
		// if ( isset( $bits->path ) ) {
		// 	$path = ' --path=' . $bits->path;
		// }
		$query = WP_CLI\Utils\esc_cmd(
			"SELECT m.meta_value %s FROM {$wp}postmeta m INNER JOIN {$wp}posts p ON p.ID=m.post_id WHERE p.post_type=%s AND m.meta_key=%s ORDER BY post_id DESC",
			'',
			'attachment',
			'_wp_attached_file'
		);
		WP_CLI::debug( $query );
		WP_CLI::debug( WP_CLI::colorize( '%GGetting attachments list from database...%n%_' ) );
		$query = sprintf(
			"db query \"%s\"$path",
			$query
		);
		$images = WP_CLI::runcommand( $query, array( 'return' => true ) );
		return explode( "\n", $images );
	}

	private function remote_image_files() {
		$path = '';
		$bits = $this->bits();
		if ( isset( $bits->path ) ) {
			$path = ' --path=' . $bits->path;
		}
		$query = WP_CLI\Utils\esc_cmd(
			"SELECT DISTINCT(m.meta_value) %s FROM \$(wp config get table_prefix$path)postmeta m INNER JOIN \$(wp config get table_prefix$path)posts p ON p.ID=m.post_id WHERE p.post_type=%s AND m.meta_key=%s",
			'',
			'attachment',
			'_wp_attached_file'
		);
		$query = sprintf(
			"wp db query \"%s\"$path",
			$query
		);
		$cmd = 'ssh';
		if ( isset( $bits->port ) ) {
			$cmd .= ' -p' . $bits->port;
		}
		$cmd .= " {$bits->ssh} %s>/tmp/files";
		WP_CLI::debug( WP_CLI::colorize( '%GGetting attachment file from remote...%n%_' ) );
		passthru( WP_CLI\Utils\esc_cmd( $cmd, $query ) );
	}
}
WP_CLI::add_command( 'uploads-pull', 'Uploads_Pull_Command' );
