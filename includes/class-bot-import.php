<?php
/**
 * Class for bot-import command
 **/
if ( ! class_exists( 'BOT_Import_Command' ) ) {
	class BOT_Import_Command extends WP_CLI_Command {
		/**
		 * Imports data from the v1 bot.ucf.edu website.
		 *
		 * ## OPTIONS
		 *
		 * <file_path>
		 * : The path to the WordPress Export file to import.
		 *
		 * ---
		 * default: success
		 * options:
		 * 	- success
		 * 	- error
		 * ---
		 *
		 * ## EXAMPLES
		 * 
		 * 	wp bot-import /path/to/file
		 *
		 * @when after_wp_load
		 */
		private
			$people = array(),
			$people_ids = array(),
			$committees = array(),
			$meetings = array();

		public function import( $args ) {
			$this->media_json_path = 'https://' . trailingslashit( $args[1] ) . 'wp-json/wp/v2/media/';
			try {
				$this->invoke_import( $args[0] );
			} catch ( Exception $e ) {
				WP_CLI::error( $e->message );
			}
		}

		private function invoke_import( $file ) {
			$parser = new WXR_Parser();
			$xml = $parser->parse( $file );
			WP_CLI::success( 'Successfully opened and parsed export file ' . $file );

			foreach( $xml['posts'] as $post ) {
				switch( $post['post_type'] ) {
					case 'person':
						$this->people[] = $post;
						$this->people_ids[$post['post_id']] = null;
						break;
					case 'committee':
						$this->committees[] = $post;
						break;
					case 'meeting':
						$this->meetings[] = $post;
						break;
					default:
						continue;
				}
			}

			$this->import_people( $people );
			$this->import_committees();
			//$this->import_meetings();
		}

		private function import_people() {
			foreach( $this->people as $person ) {
				$name = $person['post_title'];
				$post_id = post_exists( $name );
				if ( ! $post_id && $person['status'] === 'publish' ) {
					// Get the post meta
					$metas = array();
					$terms = array();

					foreach( $person['postmeta'] as $meta ) {
						$metas[$meta['key']] = $meta['value'];
					}

					if ( $person['terms'] ) {
						foreach( $person['terms'] as $term ) {
							$terms[$term['domain']][] = $term['name'];
						}
					}

					$job_title = $metas['person_job_title'];
					$phone = $metas['person_phone'];
					$email = $metas['person_email'];

					// Create an array taht can hold 
					$categories = array();

					// Make sure person_label terms exist
					if ( $terms['person_label'] ) {
						foreach( $terms['person_label'] as $label ) {
							$term = term_exists( $label, 'category' );
							if ( ! $term ) {
								$term = wp_insert_term( $label, 'category' );
							}

							$categories[] = (int)$term['term_taxonomy_id'];
						}
					}

					$post_id = wp_insert_post( array(
						'post_title'    => $name,
						'post_content'  => $person['post_content'],
						'post_type'     => 'person',
						'post_status'   => 'publish',
						'post_category' => $categories,
						'meta_input'    => array(
							'person_job_title' => $job_title,
							'person_phone'     => $phone,
							'email'            => $email
						)
					) );
				}

				$this->people_ids[$person['post_id']] = $post_id;
			}
		}

		private function import_committees() {
			foreach( $this->committees as $committee ) {
				$name = $committee['post_title'];
				if ( ! term_exists( $name, 'people_group' ) ) {
					// Get the post meta
					$metas = array();

					foreach( $committee['postmeta'] as $meta ) {
						$metas[$meta['key']] = $meta['value'];
					}

					$term = wp_insert_term( $name, 'people_group', array(
						'description' => $metas['committee_description']
					) );

					$members = maybe_unserialize( $metas['committee_members'] );
					$staff = maybe_unserialize( $metas['committee_staff'] );

					foreach( $members as $id=>$title ) {
						$post_id = $this->people_ids[$id];
						wp_set_post_terms( $post_id, $term, 'people_group', TRUE );
					}

					foreach( $staff as $id=>$title ) {
						$post_id = $this->people_ids[$id];
						wp_set_post_terms( $post_id, $term, 'people_group', TRUE );
					}
				}
			}
		}

		private function import_meetings() {

		}
	}
}
