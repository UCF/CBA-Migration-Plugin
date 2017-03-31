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
			$committee_ids = array(),
			$meetings = array(),
			$attachments = array(),
			$attachment_urls = array(),
			$documents = array();

		public function import( $args ) {
			try {
				$this->invoke_import( $args[0] );
			} catch ( Exception $e ) {
				WP_CLI::error( $e->message );
			}
		}

		public function reset( $args, $assoc_args=array() ) {
			try {
				WP_CLI::confirm( 'Art thou sure thou wants to delete all people, committees, meetings and associated media?', $assoc_args );
				$this->invoke_reset();
			} catch( Exception $e ) {
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
						$this->committee_ids[(int)$post['post_id']] = $post;
						break;
					case 'meeting':
						$this->meetings[] = $post;
						break;
					case 'attachment':
						$this->attachments[(int)$post['post_id']] = $post;
						break;
					case 'document':
						$metas = array();
						foreach( $post['postmeta'] as $meta ) {
							$metas[$meta['key']] = $meta['value'];
						}
						if ( $metas['document_file'] ) {
							$this->documents[(int)$post['post_id']] = (int)$metas['document_file'];
						}
						break;
					default:
						continue;
				}
			}
			
			$this->import_people();
			$this->import_committees();
			$this->import_meetings();

			WP_CLI::success( 'Finished importing! Have a nice day.' );
		}

		private function import_people() {
			$count = count( $this->people );
			$import_count = 0;
			$progress = \WP_CLI\Utils\make_progress_bar( 'Importing people...', $count );

			foreach( $this->people as $person ) {
				$name = $person['post_title'];
				$postdate = $person['post_date'];
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
					$thumbnail_id = (int)$metas['_thumbnail_id'];
					$thumbnail = $this->attachments[$thumbnail_id];

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
						'post_date'     => $postdate,
						'meta_input'    => array(
							'person_job_title' => $job_title,
							'person_phone'     => $phone,
							'email'            => $email
						)
					) );

					if ( $thumbnail ) {
						$this->add_attachment( $thumbnail, $post_id, '_thumbnail_id' );
					}

					$import_count++;
				}

				$this->people_ids[$person['post_id']] = $post_id;
				$progress->tick();
			}

			$progress->finish();
			WP_CLI::success( 'Successfully imported ' . $import_count . ' people.' );
		}

		private function import_committees() {
			$count = count( $this->committees );
			$import_count = 0;
			$progress = \WP_CLI\Utils\make_progress_bar( 'Importing committees...', $count );

			foreach( $this->committees as $committee ) {
				$name = $committee['post_title'];
				$term = term_exists( $name, 'people_group' );
				$import = false;

				// Get the post meta
				$metas = array();

				foreach( $committee['postmeta'] as $meta ) {
					$metas[$meta['key']] = $meta['value'];
				}

				if ( ! $term ) {
					$term = wp_insert_term( $name, 'people_group', array(
						'description' => $metas['committee_description']
					) );

					$import = true;

					$import_count++;
				}

				$members = maybe_unserialize( $metas['committee_members'] );
				$staff = maybe_unserialize( $metas['committee_staff'] );

				foreach( $members as $id=>$title ) {
					$post_id = $this->people_ids[$id];
					wp_set_post_terms( $post_id, $term['term_id'], 'people_group', TRUE );

					$post = get_post( $post_id );

					switch( $title ) {
						case 'Chair':
							update_field( 'people_group_chair', $post, 'people_group_' . $term['term_id'] );
							break;
						case 'Vice Chair':
							update_field( 'people_group_vice_chair', $post, 'people_group_' . $term['term_id'] );
							break;
						case 'Ex Officio':
							update_field( 'people_group_ex_officio', $post, 'people_group_' . $term['term_id'] );
							break;
					}
				}

				foreach( $staff as $id=>$title ) {
					$post_id = $this->people_ids[$id];
					wp_set_post_terms( $post_id, $term['term_id'], 'people_group', TRUE );
				}

				if ( $import ) {
					$document_id = (int)$metas['committee_charter'];
					$attachment_id = $this->documents[$document_id];
					$attachment = $this->attachments[$attachment_id];

					if ( $attachment ) {
						$this->add_attachment( $attachment, (int)$term['term_id'], 'people_group_charter', FALSE );
					}
				}

				$this->committee_ids[$committee['post_id']] = $term['term_id'];
				$progress->tick();
			}

			// Insert 'None' Committee option if it does not exist.
			if ( ! term_exists( 'None', 'people_group' ) ) {
				wp_insert_term( 'None', 'people_group', array(
					'description' => 'Use for meetings that are not tied to a particular committee.'
				) );
			}

			$progress->finish();
			WP_CLI::success( 'Successfully imported ' . $import_count . ' committees.' );
		}

		private function import_meetings() {
			$count = count( $this->meetings );
			$import_count = 0;
			$progress = \WP_CLI\Utils\make_progress_bar( 'Importing meetings...', $count );
			$none_term = term_exists( 'None', 'people_group' );

			foreach( $this->meetings as $meeting ) {
				$name = $meeting['post_title'];
				$post_id = post_exists( $name );
				if ( ! $post_id && $meeting['status'] === 'publish' ) {
					$content = $meeting['post_content'];
					$postdate = $meeting['post_date'];
					$metas = array();

					foreach( $meeting['postmeta'] as $meta ) {
						$metas[$meta['key']] = $meta['value'];
					}

					$committee = $this->committee_ids[(int)$metas['meeting_committee']];
					$agenda = $this->attachments[(int)$metas['meeting_agenda']];
					$minutes = $this->attachments[(int)$metas['meeting_minutes']];
					$location = $metas['meeting_location'];
					$date = new DateTime( $metas['meeting_date'] );
					try {
						$start_time = new DateTime( $metas['meeting_start_time'] );
					} catch( Exception $e ) {
						$start_time = null;
					}

					try {
						$end_time = new DateTime( $metas['meeting_end_time'] );
					} catch( Exception $e ) {
						$end_time = null;
					}

					$post_id = wp_insert_post( array(
						'post_title'             => $name,
						'post_content'           => $content,
						'post_type'              => 'meeting',
						'post_status'            => 'publish',
						'post_date'              => $postdate,
						'meta_input'             => array(
							'ucf_meeting_date'       => $date->format( 'Y-m-d' ),
							'ucf_meeting_start_time' => $start_time ? $start_time->format( 'H:i' ) : null,
							'ucf_meeting_end_time'   => $end_time ? $end_time->format( 'H:i' ) : null,
							'ucf_meeting_location'   => $location,
							'ucf_meeting_committee'  => $committee ? $committee : $none_term
						)
					) );

					if ( $agenda ) {
						$this->add_attachment( $agenda, $post_id, 'ucf_meeting_agenda' );
					}

					if ( $minutes ) {
						$this->add_attachment( $minutes, $post_id, 'ucf_meeting_minutes' );
					}
					$import_count++;
				}
				$progress->tick();
			}

			$progress->finish();
			WP_CLI::success( 'Successfully imported ' . $import_count . ' meetings.' );
		}

		private function add_attachment( $attachment, $id, $meta_key, $post=TRUE ) {
			$url = $attachment['attachment_url'];

			if ( $url === null ) {
				return;
			}
			$tmp = download_url( $url );

			if ( is_wp_error( $tmp ) ) {
				return;
			}

			$file_array['name'] = basename( $url );

			$file_array['tmp_name'] = $tmp;

			$filetype = wp_check_filetype( basename( $url ), null );
			$file_array = array_merge( $file_array, $filetype );

			$attachment_id = media_handle_sideload( $file_array, $id );

			if ( is_wp_error( $attachment_id ) ) {
                @unlink( $file_array['tmp_name'] );
				WP_CLI::warning( 'Unable to upload ' . $file_array['name'] . '.' );
			}

			if ( $id && $post && ! is_wp_error( $attachment_id ) ) {
				update_field( $meta_key, $attachment_id, $id );
			} else if ( $id && ! is_wp_error( $attachment_id ) ) {
				// Use ACF's update_field
				$id = 'people_group_' . $id;
				update_field( $meta_key, $attachment_id, $id );
			}
		}

		private function invoke_reset() {
			$people = get_posts( array( 'post_type' => 'person', 'numberposts' => -1, 'post_status' => 'any' ) );
			$committees = get_terms( array( 'taxonomy' => 'people_group', 'hide_empty' => FALSE ) );
			$meetings = get_posts( array( 'post_type' => 'meeting', 'numberposts' => -1, 'post_status' => 'any' ) );

			$people_progress = \WP_CLI\Utils\make_progress_bar( 'Deleting people...', count( $people ) );
			$committee_progress = \WP_CLI\Utils\make_progress_bar( 'Deleting committees...', count( $committees ) );
			$meeting_progress = \WP_CLI\Utils\make_progress_bar( 'Deleting meetings...', count( $meetings ) );

			foreach( $people as $person ) {
				$thumbnail_id = get_post_meta( $person->ID, '_thumbnail_id', TRUE );
				if ( $thumbnail_id ) {
					wp_delete_attachment( $thumbnail_id, TRUE );
				}
				wp_delete_post( $person->ID, TRUE );
				$people_progress->tick();
			}
			$people_progress->finish();

			foreach( $committees as $committee ) {
				$charter_id = get_field( 'people_group_charter', 'people_group_' . $committee->term_id  );
				
				if ( $charter_id ) {
					wp_delete_attachment( $charter_id, TRUE );
				}

				wp_delete_term( $committee->term_id, 'people_group', TRUE );
				$committee_progress->tick();
			}

			$committee_progress->finish();

			foreach( $meetings as $meeting ) {
				$agenda_id = get_post_meta( $meeting->ID, 'ucf_meeting_agenda', TRUE );
				$minute_id = get_post_meta( $meeting->ID, 'ucf_meeting_minutes', TRUE );

				if ( $agenda_id ) {
					wp_delete_attachment( $agenda_id, TRUE );
				}

				if ( $minute_id ) {
					wp_delete_attachment( $minute_id, TRUE );
				}

				wp_delete_post( $meeting->ID, TRUE );
				$meeting_progress->tick();
			}

			$meeting_progress->finish();

			WP_CLI::success( 'All finished!' );
		}
	}
}
