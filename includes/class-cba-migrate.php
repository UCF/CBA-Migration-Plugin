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
		 * 	wp cba-import
		 *
		 * @when after_wp_load
		 */
		private
			$people = array(),
			$degree_types = array(),
			$departments = array(),
			$org_groups = array();

		public function migrate() {
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
				'hide_empty' => false
			) );
			$this->departments = get_terms( array(
				'taxonomy' => 'departments',
				'hide_empty' => false
			) );
			$this->org_groups = get_terms( array(
				'taxonomy' => 'org_groups',
				'hide_empty' => false
			) );

			$this->migrate_people();
			$this->migrate_degree_types();
			$this->migrate_departments();
			$this->migrate_org_groups();

			WP_CLI::success( 'Finished importing! Have a nice day.' );
		}

		private function migrate_people() {
			$count = count( $this->people );
			$migrate_count = 0;
			$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating people...', $count );

			foreach( $this->people as $person ) {
				// Phone numbers
				if ( $phones = $person->person_phones ) {
					$phones = explode( ',', $phones );
					if ( $phones ) {
						foreach ( $phones as $index => $phone ) {
							update_sub_field( array( 'person_phones', $index, 'phone' ), $phone, $person->ID );
						}
					}
				}

				// Videos/Media
				if ( $medias = $person->person_media && is_array( $medias ) ) {
					foreach ( $medias as $index => $media ) {
						update_sub_field( array( 'person_media', $index, 'title' ), $media['title'], $person->ID );
						update_sub_field( array( 'person_media', $index, 'link' ), $media['link'], $person->ID );
						// NOTE: 'date' field from CBA-Theme is unused.
					}
				}

				// CV documents
				if ( $cv_id = $person->person_cv ) {
					$cv_url = wp_get_attachment_url( intval( $cv_id ) );
					if ( $cv_url ) {
						update_field( 'person_cv', $cv_url, $person->ID );
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

			foreach( $this->degree_types as $degree_type ) {
				// TODO do stuff

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

			foreach( $this->departments as $department ) {
				// TODO do stuff

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

			foreach( $this->org_groups as $org_group ) {
				// TODO do stuff

				$migrate_count++;

				$progress->tick();
			}

			$progress->finish();
			WP_CLI::success( 'Successfully migrated ' . $migrate_count . ' organization groups.' );
		}
	}
}
