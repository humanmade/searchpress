<?php
/**
 * Copyright (C) 2012-2013 Automattic
 * Copyright (C) 2013 SearchPress
 *
 * The following code is a derivative work of code from the Automattic plugin
 * WordPress.com VIP Search Add-On, which is licensed GPLv2. This code therefore
 * is also licensed under the terms of the GNU Public License, verison 2.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2 or greater,
 * as published by the Free Software Foundation.
 *
 * You may NOT assume that you can use any other version of the GPL.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * The license for this software can likely be found here:
 * http://www.gnu.org/licenses/gpl-2.0.html
 */


if ( !class_exists( 'SP_Search' ) ) :

class SP_Search {

	public $facets = array();

	private $do_found_posts;
	private $found_posts = 0;

	private $search_result;

	private static $instance;

	private function __construct() {
		/* Don't do anything, needs to be initialized via instance() method */
	}

	public function __clone() { wp_die( "Please don't __clone SP_Search" ); }

	public function __wakeup() { wp_die( "Please don't __wakeup SP_Search" ); }

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new SP_Search;
			self::$instance->setup();
		}
		return self::$instance;
	}

	public function setup() {
		if ( ! is_admin() && SP_Config()->active() ) {
			$this->init_hooks();
		}
	}

	public function init_hooks() {
		// Checks to see if we need to worry about found_posts
		add_filter( 'post_limits_request', array( $this, 'filter__post_limits_request' ), 999, 2 );

		// Replaces the standard search query with one that fetches the posts based on post IDs supplied by ES
		add_filter( 'posts_request',       array( $this, 'filter__posts_request' ),         5, 2 );

		// Nukes the FOUND_ROWS() database query
		add_filter( 'found_posts_query',   array( $this, 'filter__found_posts_query' ),     5, 2 );

		// Since the FOUND_ROWS() query was nuked, we need to supply the total number of found posts
		add_filter( 'found_posts',         array( $this, 'filter__found_posts' ),           5, 2 );
	}


	public function search( $es_args ) {
		$es_args = apply_filters( 'sp_search_query_args', $es_args );
		// sp_dump( $es_args );
		return SP_API()->search( json_encode( $es_args ), array( 'output' => ARRAY_A ) );
		# Do something with the results
		// sp_dump( $results );
	}

	public function wp_search( $wp_args ) {
		$wp_args = apply_filters( 'sp_search_wp_query_args', $wp_args );
		$es_args = $this->wp_to_es_args( $wp_args );

		return $this->search( $es_args );
	}

	public function wp_to_es_args( $args ) {
		$defaults = array(
			'query'          => null,    // Search phrase
			'query_fields'   => array( 'post_title', 'post_content', 'post_author_name', 'post_excerpt' ),

			'post_type'      => 'post',  // string or an array
			'terms'          => array(), // ex: array( 'taxonomy-1' => array( 'slug' ), 'taxonomy-2' => array( 'slug-a', 'slug-b' ) )

			'author'         => null,    // id or an array of ids
			'author_name'    => array(), // string or an array

			'date_range'     => null,    // array( 'field' => 'date', 'gt' => 'YYYY-MM-dd', 'lte' => 'YYYY-MM-dd' ); date formats: 'YYYY-MM-dd' or 'YYYY-MM-dd HH:MM:SS'

			'orderby'        => null,    // Defaults to 'relevance' if query is set, otherwise 'date'. Pass an array for multiple orders.
			'order'          => 'DESC',

			'posts_per_page' => 10,
			'offset'         => null,
			'paged'          => null,

			/**
			 * Facets. Examples:
			 * array(
			 *     'Tag'       => array( 'type' => 'taxonomy', 'taxonomy' => 'post_tag', 'count' => 10 ) ),
			 *     'Post Type' => array( 'type' => 'post_type', 'count' => 10 ) ),
			 * );
			 */
			'facets'         => null,
		);

		$raw_args = $args; // Keep a copy

		$args = wp_parse_args( $args, $defaults );

		$es_query_args = array(
			'size'    => absint( $args['posts_per_page'] ),
		);

		// ES "from" arg (offset)
		if ( $args['offset'] ) {
			$es_query_args['from'] = absint( $args['offset'] );
		} elseif ( $args['paged'] ) {
			$es_query_args['from'] = max( 0, ( absint( $args['paged'] ) - 1 ) * $es_query_args['size'] );
		}

		if ( !is_array( $args['author_name'] ) ) {
			$args['author_name'] = array( $args['author_name'] );
		}

		// ES stores usernames, not IDs, so transform
		if ( ! empty( $args['author'] ) ) {
			if ( !is_array( $args['author'] ) )
				$args['author'] = array( $args['author'] );
			foreach ( $args['author'] as $author ) {
				$user = get_user_by( 'id', $author );

				if ( $user && ! empty( $user->user_login ) ) {
					$args['author_name'][] = $user->user_login;
				}
			}
		}

		// Build the filters from the query elements.
		// Filters rock because they are cached from one query to the next
		// but they are cached as individual filters, rather than all combined together.
		// May get performance boost by also caching the top level boolean filter too.
		$filters = array();

		if ( $args['post_type'] ) {
			if ( !is_array( $args['post_type'] ) )
				$args['post_type'] = array( $args['post_type'] );
			$filters[] = array( 'terms' => array( 'post_type' => $args['post_type'] ) );
		}

		if ( $args['author_name'] ) {
			$filters[] = array( 'terms' => array( 'author_login' => $args['author_name'] ) );
		}

		if ( !empty( $args['date_range'] ) && isset( $args['date_range']['field'] ) ) {
			$field = $args['date_range']['field'];
			unset( $args['date_range']['field'] );
			$filters[] = array( 'range' => array( $field => $args['date_range'] ) );
		}

		if ( is_array( $args['terms'] ) ) {
			foreach ( $args['terms'] as $tax => $terms ) {
				$terms = (array) $terms;
				if ( count( $terms ) ) {
					switch ( $tax ) {
						case 'post_tag':
							$tax_fld = 'tag.slug';
							break;
						case 'category':
							$tax_fld = 'category.slug';
							break;
						default:
							$tax_fld = 'taxonomy.' . $tax . '.slug';
							break;
					}
					foreach ( $terms as $term ) {
						$filters[] = array( 'term' => array( $tax_fld => $term ) );
					}
				}
			}
		}

		if ( ! empty( $filters ) ) {
			$es_query_args['filter'] = array( 'and' => $filters );
		} else {
			$es_query_args['filter'] = array( 'match_all' => new stdClass() );
		}

		// Fill in the query
		//  todo: add auto phrase searching
		//  todo: add fuzzy searching to correct for spelling mistakes
		//  todo: boost title, tag, and category matches
		if ( $args['query'] ) {
			$es_query_args['query'] = array( 'multi_match' => array(
				'query'  => $args['query'],
				'fields' => $args['query_fields'],
				'operator'  => 'and',
			) );

			if ( ! $args['orderby'] ) {
				$args['orderby'] = array( 'relevance' );
			}
		} else {
			if ( ! $args['orderby'] ) {
				$args['orderby'] = array( 'date' );
			}
		}

		// Validate the "order" field
		switch ( strtolower( $args['order'] ) ) {
			case 'asc':
				$args['order'] = 'asc';
				break;
			case 'desc':
			default:
				$args['order'] = 'desc';
				break;
		}

		$es_query_args['sort'] = array();
		foreach ( (array) $args['orderby'] as $orderby ) {
			// Translate orderby from WP field to ES field
			// todo: add support for sorting by title, num likes, num comments, num views, etc
			switch ( $orderby ) {
				case 'relevance' :
					$es_query_args['sort'][] = array( '_score' => array( 'order' => $args['order'] ) );
					break;
				case 'date' :
					$es_query_args['sort'][] = array( 'date' => array( 'order' => $args['order'] ) );
					break;
				case 'ID' :
					$es_query_args['sort'][] = array( 'id' => array( 'order' => $args['order'] ) );
					break;
				case 'author' :
					$es_query_args['sort'][] = array( 'author.raw' => array( 'order' => $args['order'] ) );
					break;
			}
		}
		if ( empty( $es_query_args['sort'] ) )
			unset( $es_query_args['sort'] );

		// Facets
		if ( ! empty( $args['facets'] ) ) {
			foreach ( (array) $args['facets'] as $label => $facet ) {
				switch ( $facet['type'] ) {

					case 'taxonomy':
						switch ( $facet['taxonomy'] ) {

							case 'post_tag':
								$field = 'tag';
								break;

							case 'category':
								$field = 'category';
								break;

							default:
								$field = 'taxonomy.' . $facet['taxonomy'];
								break;
						} // switch $facet['taxonomy']

						$es_query_args['facets'][$label] = array(
							'terms' => array(
								'field' => $field . '.term_id',
								'size' => $facet['count'],
							),
						);

						break;

					case 'post_type':
						$es_query_args['facets'][$label] = array(
							'terms' => array(
								'field' => 'post_type',
								'size' => $facet['count'],
							),
						);

						break;

					case 'date_histogram':
						$es_query_args['facets'][$label] = array(
							'date_histogram' => array(
								'interval' => $facet['interval'],
								'field'    => ( ! empty( $facet['field'] ) && 'post_date_gmt' == $facet['field'] ) ? 'date_gmt' : 'date',
								'size'     => $facet['count'],
							),
						);

						break;
				}
			}
		}

		return $es_query_args;
	}

	public function filter__post_limits_request( $limits, $query ) {
		if ( ! $query->is_search() )
			return $limits;

		if ( empty( $limits ) || $query->get( 'no_found_rows' ) ) {
			$this->do_found_posts = false;
		} else {
			$this->do_found_posts = true;
		}

		return $limits;
	}

	public function filter__posts_request( $sql, $query ) {
		global $wpdb;

		if ( ! $query->is_main_query() || ! $query->is_search() )
			return $sql;

		$page = ( $query->get( 'paged' ) ) ? absint( $query->get( 'paged' ) ) : 1;

		// Start building the WP-style search query args
		// They'll be translated to ES format args later
		$es_wp_query_args = array(
			'query'          => $query->get( 's' ),
			'posts_per_page' => $query->get( 'posts_per_page' ),
			'paged'          => $page,
		);

		// Look for query variables that match registered and supported facets
		foreach ( $this->facets as $label => $facet ) {
			switch ( $facet['type'] ) {
				case 'taxonomy':
					$query_var = $this->get_taxonomy_query_var( $this->facets[ $label ]['taxonomy'] );

					if ( ! $query_var )
						continue 2;  // switch() is considered a looping structure

					if ( $query->get( $query_var ) )
						$es_wp_query_args['terms'][ $this->facets[ $label ]['taxonomy'] ] = explode( ',', $query->get( $query_var ) );

					// This plugon's custom "categery" isn't a real query_var, so manually handle it
					if ( 'category' == $query_var && ! empty( $_GET[ $query_var ] ) ) {
						$slugs = explode( ',', $_GET[ $query_var ] );

						foreach ( $slugs as $slug ) {
							$es_wp_query_args['terms'][ $this->facets[ $label ]['taxonomy'] ][] = $slug;
						}
					}

					break;

				case 'post_type':
					if ( $query->get( 'post_type' ) && 'any' != $query->get( 'post_type' ) ) {
						$post_types_via_user = $query->get( 'post_type' );
					}
					elseif ( ! empty( $_GET['post_type'] ) ) {
						$post_types_via_user = explode( ',', $_GET['post_type'] );
					} else {
						$post_types_via_user = false;
					}

					$post_types = array();

					// Validate post types, making sure they exist and are public
					if ( $post_types_via_user ) {
						foreach ( (array) $post_types_via_user as $post_type_via_user ) {
							$post_type_object = get_post_type_object( $post_type_via_user );

							if ( ! $post_type_object || $post_type_object->exclude_from_search )
								continue;

							$post_types[] = $post_type_via_user;
						}
					}

					// Default to all non-excluded from search post types
					if ( empty( $post_types ) )
						$post_types = array_values( get_post_types( array( 'exclude_from_search' => false ) ) );

					$es_wp_query_args['post_type'] = $post_types;

					break;
			}
		}

		// Date
		if ( $query->get( 'year' ) ) {
			if ( $query->get( 'monthnum' ) ) {
				// Padding
				$date_monthnum = sprintf( '%02d', $query->get( 'monthnum' ) );

				if ( $query->get( 'day' ) ) {
					// Padding
					$date_day = sprintf( '%02d', $query->get( 'day' ) );

					$date_start = $query->get( 'year' ) . '-' . $date_monthnum . '-' . $date_day . ' 00:00:00';
					$date_end   = $query->get( 'year' ) . '-' . $date_monthnum . '-' . $date_day . ' 23:59:59';
				} else {
					$days_in_month = date( 't', mktime( 0, 0, 0, $query->get( 'monthnum' ), 14, $query->get( 'year' ) ) ); // 14 = middle of the month so no chance of DST issues

					$date_start = $query->get( 'year' ) . '-' . $date_monthnum . '-01 00:00:00';
					$date_end   = $query->get( 'year' ) . '-' . $date_monthnum . '-' . $days_in_month . ' 23:59:59';
				}
			} else {
				$date_start = $query->get( 'year' ) . '-01-01 00:00:00';
				$date_end   = $query->get( 'year' ) . '-12-31 23:59:59';
			}

			$es_wp_query_args['date_range'] = array( 'field' => 'date', 'gte' => $date_start, 'lte' => $date_end );
		}

		// Facets
		if ( ! empty( $this->facets ) ) {
			$es_wp_query_args['facets'] = $this->facets;
		}

		// You can use this filter to modify the search query parameters, such as controlling the post_type.
		// These arguments are in the format for wpcom_search_api_wp_to_es_args(), i.e. WP-style.
		$es_wp_query_args = apply_filters( 'sp_search_wp_query_args', $es_wp_query_args, $query );

		// Convert the WP-style args into ES args
		$es_query_args = $this->wp_to_es_args( $es_wp_query_args );

		$es_query_args['fields'] = array( 'post_id' );

		// Do the actual search query!
		$this->search_result = $this->search( $es_query_args );

		if ( is_wp_error( $this->search_result ) || ! is_array( $this->search_result ) || empty( $this->search_result['hits'] ) || empty( $this->search_result['hits']['hits'] ) ) {
			$this->found_posts = 0;
			return "SELECT * FROM $wpdb->posts WHERE 1=0 /* SearchPress search results */";
		}

		// Get the post IDs of the results
		$post_ids = array();
		foreach ( (array) $this->search_result['hits']['hits'] as $result ) {
			// Fields arg
			if ( ! empty( $result['fields'] ) && ! empty( $result['fields']['post_id'] ) ) {
				$post_ids[] = $result['fields']['post_id'];
			}
			// Full source objects
			elseif ( ! empty( $result['_source'] ) && ! empty( $result['_source']['id'] ) ) {
				$post_ids[] = $result['_source']['id'];
			}
			// Unknown results format
			else {
				return '';//$sql;
			}
		}

		// Total number of results for paging purposes
		$this->found_posts = $this->search_result['hits']['total'];

		// Replace the search SQL with one that fetches the exact posts we want in the order we want
		$post_ids_string = implode( ',', array_map( 'absint', $post_ids ) );
		return "SELECT * FROM {$wpdb->posts} WHERE {$wpdb->posts}.ID IN( {$post_ids_string} ) ORDER BY FIELD( {$wpdb->posts}.ID, {$post_ids_string} ) /* SearchPress search results */";
	}

	public function filter__found_posts_query( $sql, $query ) {
		if ( ! $query->is_main_query() || ! $query->is_search() )
			return $sql;

		return '';
	}

	public function filter__found_posts( $found_posts, $query ) {
		if ( ! $query->is_main_query() || ! $query->is_search() )
			return $found_posts;

		return $this->found_posts;
	}

	public function set_facets( $facets ) {
		$this->facets = $facets;
	}

	public function get_search_result( $raw = false ) {
		if ( $raw )
			return $this->search_result;

		return ( ! empty( $this->search_result ) && ! is_wp_error( $this->search_result ) && is_array( $this->search_result ) && ! empty( $this->search_result['hits'] ) ) ? $this->search_result['hits'] : false;
	}

	public function get_search_facets() {
		$search_result = $this->get_search_result();
		return ( ! empty( $search_result ) && ! empty( $search_result['facets'] ) ) ? $search_result['facets'] : array();
	}

	// Turns raw ES facet data into data that is more useful in a WordPress setting
	public function get_search_facet_data() {
		if ( empty( $this->facets ) )
			return false;

		$facets = $this->get_search_facets();

		if ( ! $facets )
			return false;

		$facet_data = array();

		foreach ( $facets as $label => $facet ) {
			if ( empty( $this->facets[ $label ] ) )
				continue;

			$facets_data[ $label ] = $this->facets[ $label ];
			$facets_data[ $label ]['items'] = array();

			// All taxonomy terms are going to have the same query_var
			if( 'taxonomy' == $this->facets[ $label ]['type'] ) {
				$tax_query_var = $this->get_taxonomy_query_var( $this->facets[ $label ]['taxonomy'] );

				if ( ! $tax_query_var )
					continue;

				$existing_term_slugs = ( get_query_var( $tax_query_var ) ) ? explode( ',', get_query_var( $tax_query_var ) ) : array();

				// This plugon's custom "categery" isn't a real query_var, so manually handle it
				if ( 'category' == $tax_query_var && ! empty( $_GET[ $tax_query_var ] ) ) {
					$slugs = explode( ',', $_GET[ $tax_query_var ] );

					foreach ( $slugs as $slug ) {
						$existing_term_slugs[] = $slug;
					}
				}
			}

			$items = array();
			if ( ! empty( $facet['terms'] ) ) {
				$items = (array) $facet['terms'];
			}
			elseif ( ! empty( $facet['entries'] ) ) {
				$items = (array) $facet['entries'];
			}

			// Some facet types like date_histogram don't support the max results parameter
			if ( count( $items ) > $this->facets[ $label ]['count'] ) {
				$items = array_slice( $items, 0, $this->facets[ $label ]['count'] );
			}

			foreach ( $items as $item ) {
				$query_vars = array();

				switch ( $this->facets[ $label ]['type'] ) {
					case 'taxonomy':
						$term = get_term_by( 'id', $item['term'], $this->facets[ $label ]['taxonomy'] );

						if ( ! $term )
							continue 2; // switch() is considered a looping structure

						// Don't allow refinement on a term we're already refining on
						if ( in_array( $term->slug, $existing_term_slugs ) )
							continue 2;

						$slugs = array_merge( $existing_term_slugs, array( $term->slug ) );

						$query_vars = array( $tax_query_var => implode( ',', $slugs ) );
						$name       = $term->name;

						break;

					case 'post_type':
						$post_type = get_post_type_object( $item['term'] );

						if ( ! $post_type || $post_type->exclude_from_search )
							continue 2;  // switch() is considered a looping structure

						$query_vars = array( 'post_type' => $item['term'] );
						$name       = $post_type->labels->singular_name;

						break;

					case 'date_histogram':
						$timestamp = $item['time'] / 1000;

						switch ( $this->facets[ $label ]['interval'] ) {
							case 'year':
								$query_vars = array(
									'year'     => date( 'Y', $timestamp ),
									'monthnum' => false,
									'day'      => false,
								);
								$name = date( 'Y', $timestamp );
								break;

							case 'month':
								$query_vars = array(
									'year'     => date( 'Y', $timestamp ),
									'monthnum' => date( 'n', $timestamp ),
									'day'      => false,
								);
								$name = date( 'F Y', $timestamp );
								break;

							case 'day':
								$query_vars = array(
									'year'     => date( 'Y', $timestamp ),
									'monthnum' => date( 'n', $timestamp ),
									'day'      => date( 'j', $timestamp ),
								);
								$name = date( 'F jS, Y', $timestamp );
								break;

							default:
								continue 3; // switch() is considered a looping structure
						}

						break;

					default:
						//continue 2; // switch() is considered a looping structure
				}

				$facets_data[ $label ]['items'][] = array(
					'url'        => add_query_arg( $query_vars ),
					'query_vars' => $query_vars,
					'name'       => $name,
					'count'      => $item['count'],
				);
			}
		}

		return $facets_data;
	}

	public function get_current_filters() {
		$filters = array();

		// Process dynamic query string keys (i.e. taxonomies)
		foreach ( $this->facets as $label => $facet ) {
			switch ( $facet['type'] ) {
				case 'taxonomy':
					$query_var = $this->get_taxonomy_query_var( $facet['taxonomy'] );

					if ( ! $query_var || empty( $_GET[ $query_var ] ) )
						continue 2;  // switch() is considered a looping structure

					$slugs = explode( ',', $_GET[ $query_var ] );

					$slug_count = count( $slugs );

					foreach ( $slugs as $slug ) {
						// Todo: caching here
						$term = get_term_by( 'slug', $slug, $facet['taxonomy'] );

						if ( ! $term || is_wp_error( $term ) )
							continue;

						$url = ( $slug_count > 1 ) ? add_query_arg( $query_var, implode( ',', array_diff( $slugs, array( $slug ) ) ) ) : remove_query_arg( $query_var );

						$filters[] = array(
							'url'  => $url,
							'name' => $term->name,
							'type' => ( ! empty( $facet['singular_title'] ) ) ? $facet['singular_title'] : get_taxonomy( $facet['taxonomy'] )->labels->singular_name,
						);
					}

					break;

				case 'post_type':
					if ( empty( $_GET['post_type'] ) )
						continue 2;

					$post_types = explode( ',', $_GET[ 'post_type' ] );

					$post_type_count = count( $post_types );

					foreach ( $post_types as $post_type ) {
						$post_type_object = get_post_type_object( $post_type );

						if ( ! $post_type_object )
							continue;

						$url = ( $post_type_count > 1 ) ? add_query_arg( 'post_type', implode( ',', array_diff( $post_types, array( $post_type ) ) ) ) : remove_query_arg( 'post_type' );

						$filters[] = array(
							'url'  => $url,
							'name' => $post_type_object->labels->singular_name,
							'type' => ( ! empty( $facet['singular_title'] ) ) ? $facet['singular_title'] : $label,
						);
					}

					break;

				case 'date_histogram':
					switch ( $facet['interval'] ) {
						case 'year':
							if ( empty( $_GET['year'] ) )
								continue 3;

							$filters[] = array(
								'url'  => remove_query_arg( array( 'year', 'monthnum', 'day' ) ),
								'name' => absint( $_GET['year'] ),
								'type' => __( 'Year', 'wpcom-elasticsearch' ),
							);

							break;

						case 'month':
							if ( empty( $_GET['year'] ) || empty( $_GET['monthnum'] ) )
								continue;

							$filters[] = array(
								'url'  => remove_query_arg( array( 'monthnum', 'day' ) ),
								'name' => date( 'F Y', mktime( 0, 0, 0, absint( $_GET['monthnum'] ), 14, absint( $_GET['year'] ) ) ),
								'type' => __( 'Month', 'wpcom-elasticsearch' ),
							);

							break;

						case 'day':

							if ( empty( $_GET['year'] ) || empty( $_GET['monthnum'] ) || empty( $_GET['day'] ) )
								continue;

							$filters[] = array(
								'url'  => remove_query_arg( 'day' ),
								'name' => date( 'F jS, Y', mktime( 0, 0, 0, absint( $_GET['monthnum'] ), absint( $_GET['day'] ), absint( $_GET['year'] ) ) ),
								'type' => __( 'Day', 'wpcom-elasticsearch' ),
							);

							break;

						default:
							continue 3;
					}

					break;

			} // end switch()
		}

		return $filters;
	}

	public function get_taxonomy_query_var( $taxonomy_name ) {
		$taxonomy = get_taxonomy( $taxonomy_name );

		if ( ! $taxonomy || is_wp_error( $taxonomy ) )
			return false;

		// category_name only accepts a single slug so make a custom, fake query var for categories
		if ( 'category_name' == $taxonomy->query_var )
			$taxonomy->query_var = 'category';

		return $taxonomy->query_var;
	}

}

function SP_Search() {
	return SP_Search::instance();
}
add_action( 'after_setup_theme', 'SP_Search' );

endif;