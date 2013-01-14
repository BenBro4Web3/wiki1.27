<?php
/**
 *
 *
 * Created on July 7, 2007
 *
 * Copyright © 2006 Yuri Astrakhan "<Firstname><Lastname>@gmail.com"
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

/**
 * Query module to enumerate links from all pages together.
 *
 * @ingroup API
 */
class ApiQueryAllLinks extends ApiQueryGeneratorBase {

	public function __construct( $query, $moduleName ) {
		switch ( $moduleName ) {
			case 'alllinks':
				$prefix = 'al';
				$this->table = 'pagelinks';
				$this->tablePrefix = 'pl_';
				$this->dfltNamespace = NS_MAIN;
				$this->indexTag = 'l';
				$this->description = 'Enumerate all links that point to a given namespace';
				$this->descriptionLink = 'link';
				$this->descriptionLinked = 'linked';
				$this->descriptionLinking = 'linking';
				break;
			case 'alltransclusions':
				$prefix = 'at';
				$this->table = 'templatelinks';
				$this->tablePrefix = 'tl_';
				$this->dfltNamespace = NS_TEMPLATE;
				$this->indexTag = 't';
				$this->description = 'List all transclusions (pages embedded using {{x}}), including non-existing';
				$this->descriptionLink = 'transclusion';
				$this->descriptionLinked = 'transcluded';
				$this->descriptionLinking = 'transcluding';
				break;
			default:
				ApiBase::dieDebug( __METHOD__, 'Unknown module name' );
		}

		parent::__construct( $query, $moduleName, $prefix );
	}

	public function execute() {
		$this->run();
	}

	public function getCacheMode( $params ) {
		return 'public';
	}

	public function executeGenerator( $resultPageSet ) {
		$this->run( $resultPageSet );
	}

	/**
	 * @param $resultPageSet ApiPageSet
	 * @return void
	 */
	private function run( $resultPageSet = null ) {
		$db = $this->getDB();
		$params = $this->extractRequestParams();

		$pfx = $this->tablePrefix;
		$prop = array_flip( $params['prop'] );
		$fld_ids = isset( $prop['ids'] );
		$fld_title = isset( $prop['title'] );

		if ( $params['unique'] ) {
			if ( $fld_ids ) {
				$this->dieUsage(
					"{$this->getModuleName()} cannot return corresponding page ids in unique {$this->descriptionLink}s mode",
					'params' );
			}
			$this->addOption( 'DISTINCT' );
		}

		$this->addTables( $this->table );
		$this->addWhereFld( $pfx . 'namespace', $params['namespace'] );

		$continue = !is_null( $params['continue'] );
		if ( $continue ) {
			$continueArr = explode( '|', $params['continue'] );
			$op = $params['dir'] == 'descending' ? '<' : '>';
			if ( $params['unique'] ) {
				if ( count( $continueArr ) != 1 ) {
					$this->dieUsage( 'Invalid continue parameter', 'badcontinue' );
				}
				$continueTitle = $db->addQuotes( $continueArr[0] );
				$this->addWhere( "{$pfx}title $op= $continueTitle" );
			} else {
				if ( count( $continueArr ) != 2 ) {
					$this->dieUsage( 'Invalid continue parameter', 'badcontinue' );
				}
				$continueTitle = $db->addQuotes( $continueArr[0] );
				$continueFrom = intval( $continueArr[1] );
				$this->addWhere(
					"{$pfx}title $op $continueTitle OR " .
					"({$pfx}title = $continueTitle AND " .
					"{$pfx}from $op= $continueFrom)"
				);
			}
		}

		// 'continue' always overrides 'from'
		$from = ( $continue || is_null( $params['from'] ) ? null : $this->titlePartToKey( $params['from'] ) );
		$to = ( is_null( $params['to'] ) ? null : $this->titlePartToKey( $params['to'] ) );
		$this->addWhereRange( $pfx . 'title', 'newer', $from, $to );

		if ( isset( $params['prefix'] ) ) {
			$this->addWhere( $pfx . 'title' . $db->buildLike( $this->titlePartToKey( $params['prefix'] ), $db->anyString() ) );
		}

		$this->addFields( array( 'pl_title' => $pfx . 'title' ) );
		$this->addFieldsIf( array( 'pl_from' => $pfx . 'from' ), !$params['unique'] );

		$this->addOption( 'USE INDEX', $pfx . 'namespace' );
		$limit = $params['limit'];
		$this->addOption( 'LIMIT', $limit + 1 );

		$sort = ( $params['dir'] == 'descending' ? ' DESC' : '' );
		$orderBy = array();
		$orderBy[] = $pfx . 'title' . $sort;
		if ( !$params['unique'] ) {
			$orderBy[] = $pfx . 'from' . $sort;
		}
		$this->addOption( 'ORDER BY', $orderBy );

		$res = $this->select( __METHOD__ );

		$pageids = array();
		$titles = array();
		$count = 0;
		$result = $this->getResult();
		foreach ( $res as $row ) {
			if ( ++ $count > $limit ) {
				// We've reached the one extra which shows that there are additional pages to be had. Stop here...
				if ( $params['unique'] ) {
					$this->setContinueEnumParameter( 'continue', $row->pl_title );
				} else {
					$this->setContinueEnumParameter( 'continue', $row->pl_title . '|' . $row->pl_from );
				}
				break;
			}

			if ( is_null( $resultPageSet ) ) {
				$vals = array();
				if ( $fld_ids ) {
					$vals['fromid'] = intval( $row->pl_from );
				}
				if ( $fld_title ) {
					$title = Title::makeTitle( $params['namespace'], $row->pl_title );
					ApiQueryBase::addTitleInfo( $vals, $title );
				}
				$fit = $result->addValue( array( 'query', $this->getModuleName() ), null, $vals );
				if ( !$fit ) {
					if ( $params['unique'] ) {
						$this->setContinueEnumParameter( 'continue', $row->pl_title );
					} else {
						$this->setContinueEnumParameter( 'continue', $row->pl_title . '|' . $row->pl_from );
					}
					break;
				}
			} elseif ( $params['unique'] ) {
				$titles[] = Title::makeTitle( $params['namespace'], $row->pl_title );
			} else {
				$pageids[] = $row->pl_from;
			}
		}

		if ( is_null( $resultPageSet ) ) {
			$result->setIndexedTagName_internal( array( 'query', $this->getModuleName() ), $this->indexTag );
		} elseif ( $params['unique'] ) {
			$resultPageSet->populateFromTitles( $titles );
		} else {
			$resultPageSet->populateFromPageIDs( $pageids );
		}
	}

	public function getAllowedParams() {
		return array(
			'continue' => null,
			'from' => null,
			'to' => null,
			'prefix' => null,
			'unique' => false,
			'prop' => array(
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_DFLT => 'title',
				ApiBase::PARAM_TYPE => array(
					'ids',
					'title'
				)
			),
			'namespace' => array(
				ApiBase::PARAM_DFLT => $this->dfltNamespace,
				ApiBase::PARAM_TYPE => 'namespace'
			),
			'limit' => array(
				ApiBase::PARAM_DFLT => 10,
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_BIG1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_BIG2
			),
			'dir' => array(
				ApiBase::PARAM_DFLT => 'ascending',
				ApiBase::PARAM_TYPE => array(
					'ascending',
					'descending'
				)
			),
		);
	}

	public function getParamDescription() {
		$p = $this->getModulePrefix();
		$link = $this->descriptionLink;
		$linking = $this->descriptionLinking;
		return array(
			'from' => "The title of the $link to start enumerating from",
			'to' => "The title of the $link to stop enumerating at",
			'prefix' => "Search for all $link titles that begin with this value",
			'unique' => array(
					"Only show distinct $link titles. Cannot be used with {$p}prop=ids.",
					'When used as a generator, yields target pages instead of source pages.',
			),
			'prop' => array(
				'What pieces of information to include',
				" ids    - Adds the pageid of the $linking page (Cannot be used with {$p}unique)",
				" title  - Adds the title of the $link",
			),
			'namespace' => 'The namespace to enumerate',
			'limit' => "How many total items to return",
			'continue' => 'When more results are available, use this to continue',
			'dir' => 'The direction in which to list',
		);
	}

	public function getResultProperties() {
		return array(
			'ids' => array(
				'fromid' => 'integer'
			),
			'title' => array(
				'ns' => 'namespace',
				'title' => 'string'
			)
		);
	}

	public function getDescription() {
		return $this->description;
	}

	public function getPossibleErrors() {
		$m = $this->getModuleName();
		$link = $this->descriptionLink;
		return array_merge( parent::getPossibleErrors(), array(
			array( 'code' => 'params', 'info' => "{$m} cannot return corresponding page ids in unique {$link}s mode" ),
			array( 'code' => 'badcontinue', 'info' => 'Invalid continue parameter' ),
		) );
	}

	public function getExamples() {
		$p = $this->getModulePrefix();
		$link = $this->descriptionLink;
		$linked = $this->descriptionLinked;
		return array(
			"api.php?action=query&list=all{$link}s&{$p}from=B&{$p}prop=ids|title"
					=> "List $linked titles with page ids they are from, including missing ones. Start at B",
			"api.php?action=query&list=all{$link}s&{$p}unique=&{$p}from=B"
					=> "List unique $linked titles",
			"api.php?action=query&generator=all{$link}s&g{$p}unique=&g{$p}from=B"
					=> "Gets all $link targets, marking the missing ones",
			"api.php?action=query&generator=all{$link}s&g{$p}from=B"
					=> "Gets pages containing the {$link}s",
		);
	}

	public function getHelpUrls() {
		return "https://www.mediawiki.org/wiki/API:All{$this->descriptionLink}s";
	}

	public function getVersion() {
		return __CLASS__ . ': $Id$';
	}
}
