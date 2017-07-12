<?php
/**
 * Class for cba-migrate command
 **/
if ( ! class_exists( 'CBA_Migrate_Command' ) ) {
	class CBA_Migrate_Command extends WP_CLI_Command {
		/**
		 * Migrates data from the v1 business.ucf.edu website for use with College-Theme and its supported plugins.
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
		 * 	wp cba migrate
		 *
		 * @when after_wp_load
		 */
		private
			$people = array(),
			$degree_types = array(),
			$departments = array(),
			$org_groups = array(),
			$publications = array(),
			$site_url;

		public function __invoke( $args ) {
			if ( !class_exists( 'acf' ) ) {
				WP_CLI::error( 'The Colleges-Theme requires the Advanced Custom Fields plugin for storing post metadata. Please install ACF and try again.' );
			}

			try {
				WP_CLI::confirm( 'Are you sure you want to permanently migrate this site\'s data for use with the College-Theme\'s supported post types and meta fields? This action cannot be undone.' );
				$this->invoke_migration();
			} catch ( Exception $e ) {
				WP_CLI::error( $e->message );
			}
		}

		private function invoke_migration() {
			// Set Site URL
			$this->site_url = get_site_url();

			// Temporarily re-activate old taxonomies and post types so that
			// get_terms() and get_posts() can return successfully.
			register_post_type( 'publication' );
			register_taxonomy( 'degree_types', 'degree' );
			register_taxonomy( 'org_groups', 'person' );
			register_taxonomy( 'publication_types', 'publication' );

			// Fetch initial post/term data
			$this->people = get_posts( array(
				'post_type' => 'person',
				'post_status' => 'any',
				'numberposts' => -1
			) );
			$this->degree_types = get_terms( array(
				'taxonomy' => 'degree_types',
				'hide_empty' => false,
				'fields' => 'id=>name'
			) );
			$this->departments = get_terms( array(
				'taxonomy' => 'departments',
				'hide_empty' => false,
				'fields' => 'ids'
			) );
			$this->org_groups = get_terms( array(
				'taxonomy' => 'org_groups',
				'hide_empty' => false,
				'fields' => 'id=>name'
			) );
			$this->publications = get_posts( array(
				'post_type' => 'publication',
				'post_status' => 'any',
				'numberposts' => -1
			) );

			// Migrate the data
			$this->migrate_people();
			$this->migrate_degree_types();
			$this->migrate_departments();
			$this->migrate_org_groups();
			$this->migrate_publications();

			// Re-deactivate old taxonomies and post types
			unregister_taxonomy( 'degree_types' );
			unregister_taxonomy( 'org_groups' );
			unregister_taxonomy( 'publication_types' );
			unregister_post_type( 'publication' );

			WP_CLI::success( 'Finished importing! Have a nice day.' );
		}

		private function migrate_people() {
			$count = count( $this->people );
			$migrate_count = 0;
			$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating people...', $count );

			foreach( $this->people as $person ) {
				// Phone numbers
				if ( $phones = $person->person_phones ) {
					if ( is_string( $phones ) ) {
						$phones = explode( ',', $phones );
						delete_field( 'person_phone_numbers', $person->ID ); // delete existing ACF field data, just in case

						foreach ( $phones as $phone ) {
							add_row( 'person_phone_numbers', array( 'number' => $phone ), $person->ID );
						}
					}
				}

				// Videos/Media
				if ( $medias = $person->person_media ) {
					if ( is_array( $medias ) ) {
						delete_field( 'person_medias', $person->ID ); // delete existing ACF field data, just in case

						foreach ( $medias as $media ) {
							$media_title = $media['title'];
							$media_link  = $media['link'];

							// Fix instances of relative youtube urls, which
							// won't work with ACF's oembed fields
							if ( substr( $media_link, 0, 2 ) === '//' ) {
								$media_link = str_replace( '//', 'https://', $media_link );
							}

							add_row( 'person_medias', array(
								// NOTE: 'date' field from CBA-Theme is unused.
								'title' => $media_title,
								'link'  => $media_link
							), $person->ID );
						}
					}
				}

				// CV documents
				if ( $cv_id = $person->person_cv ) {
					// Force string to int conversion here so that ACF stores
					// the value properly and can return it using the correct
					// formatting when get_field() is called.
					update_field( 'person_cv', intval( $cv_id ), $person->ID );
				}

				$migrate_count++;

				$progress->tick();
			}

			$progress->finish();
			WP_CLI::log( 'Successfully migrated ' . $migrate_count . ' people.' );
		}

		private function migrate_degree_types() {
			$count = count( $this->degree_types );
			$migrate_count = 0;
			$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating degree types...', $count );

			foreach( $this->degree_types as $degree_type_id => $degree_type ) {
				// Perform a direct 1-to-1 migration to the 'program_types' taxonomy
				$program_type = wp_insert_term( $degree_type, 'program_types' );

				if ( !is_wp_error( $program_type ) ) {
					// Re-assign tagged Degree posts
					$grouped_degrees = get_posts( array(
						'post_type' => 'degree',
						'post_status' => 'any',
						'numberposts' => -1,
						'tax_query' => array(
							array(
								'taxonomy' => 'degree_types',
								'field' => 'id',
								'terms' => $degree_type_id
							)
						)
					) );
					if ( $grouped_degrees && is_array( $grouped_degrees ) ) {
						foreach ( $grouped_degrees as $degree ) {
							wp_set_object_terms( $degree->ID, $program_type['term_id'], 'program_types' );
						}
					}

					$migrate_count++;
				}
				else {
					WP_CLI::warning( 'Failed to create new program type ' . $degree_type . ': ' . $program_type->get_error_message() );
				}

				$progress->tick();
			}

			$progress->finish();
			WP_CLI::log( 'Successfully migrated ' . $migrate_count . ' degree types.' );
		}

		private function migrate_departments() {
			$count = count( $this->departments );
			$migrate_count = 0;
			$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating departments...', $count );

			foreach( $this->departments as $department_id ) {
				// Re-map the 'Department Links to Page' faux-meta field to
				// the 'Department Website' term meta field
				if ( $linked_page_id = $this->get_term_custom_meta( $department_id, 'departments', 'department_links_to_page' ) ) {
					if ( $permalink = get_permalink( $linked_page_id ) ) {
						update_term_meta( $department_id, 'departments_website', $permalink );
					}
				}

				$migrate_count++;

				$progress->tick();
			}

			$progress->finish();
			WP_CLI::log( 'Successfully migrated ' . $migrate_count . ' departments.' );
		}

		private function migrate_org_groups() {
			$count = count( $this->org_groups );
			$migrate_count = 0;
			$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating organization groups...', $count );

			foreach( $this->org_groups as $org_group_id => $org_group ) {
				// Perform a direct 1-to-1 migration to the 'people_group' taxonomy
				$person_group = wp_insert_term( $org_group, 'people_group' );

				if ( !is_wp_error( $person_group ) ) {
					// Re-assign tagged Person posts
					if ( is_array( $person_group ) ) {
						$grouped_people = get_posts( array(
							'post_type' => 'person',
							'post_status' => 'any',
							'numberposts' => -1,
							'tax_query' => array(
								array(
									'taxonomy' => 'org_groups',
									'field' => 'id',
									'terms' => $org_group_id
								)
							)
						) );
						if ( $grouped_people && is_array( $grouped_people ) ) {
							foreach ( $grouped_people as $person ) {
								wp_set_object_terms( $person->ID, $person_group['term_id'], 'people_group' );
							}
						}
					}

					$migrate_count++;
				}
				else {
					WP_CLI::warning( 'Failed to create new person group ' . $org_group . ': ' . $person_group->get_error_message() );
				}

				$progress->tick();
			}

			$progress->finish();
			WP_CLI::log( 'Successfully migrated ' . $migrate_count . ' organization groups.' );
		}

		private function migrate_publications() {
			$count = count( $this->publications );
			$migrate_count = 0;
			$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating publications...', $count );

			// Create new 'publication' category or return existing
			$pub_cat_id = intval( wp_create_category( 'Publication' ) );
			// Create new 'featured' category or return existing
			$featured_cat_id = intval( wp_create_category( 'Featured' ) );

			// Copy publications into posts and categorize
			foreach( $this->publications as $pub ) {
				$pub_pubtypes       = wp_get_post_terms( $pub->ID, 'publication_types', array( 'fields' => 'names' ) );
				$pub_people         = get_post_meta( $pub->ID, 'publication_people', true );
				$page_links_to      = get_post_meta( $pub->ID, '_links_to', true );
				$links_to_target    = get_post_meta( $pub->ID, '_links_to_target', true );
				$redirected_post_id = null;
				$redirected_post    = null;
				$redirects_to_post  = false;
				$migrated_post      = null;
				$migrated_post_id   = null;
				$migrated_post_cats = array( $pub_cat_id );

				if ( $page_links_to ) {
					// Handle checks against other environments with imported
					// data from prod
					if ( strpos( $page_links_to, 'business.ucf.edu' ) !== false && strpos( $this->site_url, 'business.ucf.edu' ) === false ) {
						$page_links_to = preg_replace( '/http(s)?\:\/\/business\.ucf\.edu/', $this->site_url, $page_links_to );
					}

					$redirected_post_id = url_to_postid( $page_links_to );
					$redirected_post    = $redirected_post_id ? get_post( $redirected_post_id ) : false;

					if ( $redirected_post ) {
						$redirects_to_post = $redirected_post->post_type === 'post';
					}
				}

				// Get an existing post to modify, or create a new one
				if ( $redirects_to_post ) {
					WP_CLI::log( 'Publication ' . $pub->post_title . ' redirects to an existing post. Using it instead.' );
					// If the redirect goes to an existing post, use that post
					// (avoid creating duplicate posts)
					$migrated_post = $redirected_post;
				}
				else {
					// Check for existing migrated publications
					if (
						!empty( $pub->post_title )
						&& $existing_post = get_posts( array(
							'numberposts' => 1,
							'post_type' => 'post',
							'post_status' => 'any',
							'title' => $pub->post_title,
							'category' => $pub_cat_id
						) )
					) {
						WP_CLI::log( 'Publication ' . $pub->post_title . ' has already been migrated. Updating existing post instead.' );
						$migrated_post = $existing_post[0];
					}
					else {
						// Publication can be migrated directly as-is; create a new post
						$migrated_post = clone $pub;
						$migrated_post->post_type = 'post';
						unset( $migrated_post->ID );
						unset( $migrated_post->guid );
						$migrated_post = wp_insert_post( (array)$migrated_post, true );
					}
				}

				// Update post metadata and categories
				if ( !is_wp_error( $migrated_post ) ) {
					// Normalize migrated post id ($migrated post can be a
					// WP Post obj or post ID at this point)
					$migrated_post_id = is_object( $migrated_post ) ? $migrated_post->ID : intval( $migrated_post ) ;

					// Re-save Page Links To metadata
					if ( $page_links_to && $redirected_post_id !== $migrated_post_id ) {
						update_post_meta( $migrated_post_id, '_links_to', $page_links_to );
					}
					if ( $links_to_target && $redirected_post_id !== $migrated_post_id ) {
						update_post_meta( $migrated_post_id, '_links_to_target', $links_to_target );
					}

					// Migrate publication-to-person relationship meta.
					// Conveniently ACF relationship fields store data the same
					// way it was being stored in CBA-Theme (array of IDs).
					if ( $pub_people ) {
						update_field( 'post_associated_people', $pub_people, $migrated_post_id );
					}

					// Apply relevant categories
					if ( $pub_pubtypes ) {
						foreach ( $pub_pubtypes as $pubtype ) {
							if ( $pubtype === 'Featured' ) {
								$migrated_post_cats[] = $featured_cat_id;
							}
							else {
								if ( $mapped_cat_id = get_cat_ID( $pubtype ) ) {
									$migrated_post_cats[] = $mapped_cat_id;
								}
								else {
									WP_CLI::warning( 'Failed to convert publication_type ' . $pubtype . ' for publication ' . $pub->post_title . ': matching post category not found' );
								}
							}
						}
					}
					wp_set_post_categories( $migrated_post_id, $migrated_post_cats );

					$migrate_count++;
				}
				else {
					WP_CLI::warning( 'Failed to create new post from publication ' . $pub->post_title . ': ' . $migrated_post->get_error_message() );
				}

				$progress->tick();
			}

			$progress->finish();
			WP_CLI::log( 'Successfully migrated ' . $migrate_count . ' publications.' );
		}

		/**
		 * Return's a term's custom meta value by key name.
		 * Assumes that term data are saved as options using the naming schema
		 * 'tax_TAXONOMY-SLUG_TERMID'.
		 *
		 * Copied from CBA-Theme
		 **/
		private function get_term_custom_meta( $term_id, $taxonomy, $key ) {
			if ( empty( $term_id ) || empty( $taxonomy ) || empty( $key ) ) {
				return false;
			}

			$term_meta = get_option( 'tax_' + $taxonomy + '_' + $term_id );
			if ( $term_meta && isset( $term_meta[$key] ) ) {
				$val = $term_meta[$key];
			}
			else {
				$val = false;
			}
			return $val;
		}
	}
}
