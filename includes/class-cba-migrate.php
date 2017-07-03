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
			$org_groups = array();

		public function __invoke( $args ) {
			if ( !class_exists( 'acf' ) ) {
				WP_CLI::error( 'The Colleges-Theme requires the Advanced Custom Fields plugin for storing post metadata. Please install ACF and try again.' );
				return; // TODO does this need to return here?
			}

			try {
				WP_CLI::confirm( 'Are you sure you want to permanently migrate this site\'s data for use with the College-Theme\'s supported post types and meta fields? This action cannot be undone.' );
				$this->invoke_migration();
			} catch ( Exception $e ) {
				WP_CLI::error( $e->message );
			}
		}

		private function invoke_migration() {
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

			$this->migrate_people();
			// $this->migrate_degree_types();
			// $this->migrate_departments();
			// $this->migrate_org_groups();

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
							add_row( 'person_medias', array(
								// NOTE: 'date' field from CBA-Theme is unused.
								'title' => $media['title'],
								'link'  => $media['link']
							), $person->ID );
						}
					}
				}

				$migrate_count++;

				$progress->tick();
			}

			$progress->finish();
			WP_CLI::success( 'Successfully migrated ' . $migrate_count . ' people.' );
		}

		private function migrate_degree_types() {
			$count = count( $this->degree_types );
			$migrate_count = 0;
			$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating degree types...', $count );

			foreach( $this->degree_types as $degree_type_id => $degree_type ) {
				// Perform a direct 1-to-1 migration to the 'program_types' taxonomy
				$program_type = wp_insert_term( $degree_type, 'program_types' );

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
				else {
					// TODO raise exception--something went wrong
				}

				$migrate_count++;

				$progress->tick();
			}

			$progress->finish();
			WP_CLI::success( 'Successfully migrated ' . $migrate_count . ' degree types.' );
		}

		private function migrate_departments() {
			$count = count( $this->departments );
			$migrate_count = 0;
			$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating departments...', $count );

			foreach( $this->departments as $department_id ) {
				// Re-map the 'Department Links to Page' faux-meta field to
				// the 'Department Website' term meta field
				$links_to_optionname = 'tax_departments_' . $department_id . '["department_links_to_page"]';
				if ( $dept_links_to_page = get_option( $links_to_optionname ) ) {
					update_term_meta( $department_id, 'departments_website', get_permalink( $dept_links_to_page ) );
				}

				$migrate_count++;

				$progress->tick();
			}

			$progress->finish();
			WP_CLI::success( 'Successfully migrated ' . $migrate_count . ' departments.' );
		}

		private function migrate_org_groups() {
			$count = count( $this->org_groups );
			$migrate_count = 0;
			$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating organization groups...', $count );

			foreach( $this->org_groups as $org_group_id => $org_group ) {
				// Perform a direct 1-to-1 migration to the 'people_groups' taxonomy
				$person_group = wp_insert_term( $org_group, 'people_groups' );

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
							wp_set_object_terms( $person->ID, $person_group['term_id'], 'people_groups' );
						}
					}
				}
				else {
					// TODO raise exception--something went wrong
				}

				$migrate_count++;

				$progress->tick();
			}

			$progress->finish();
			WP_CLI::success( 'Successfully migrated ' . $migrate_count . ' organization groups.' );
		}
	}
}