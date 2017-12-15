<?php
/* vim: set expandtab sw=4 ts=4 sts=4: */
/**
 * Handles Database Search
 *
 * @package PhpMyAdmin
 */
namespace PhpMyAdmin\Database;

use PhpMyAdmin\Sanitize;
use PhpMyAdmin\Template;
use PhpMyAdmin\Url;
use PhpMyAdmin\Util;

/**
 * Class to handle database search
 *
 * @package PhpMyAdmin
 */
class Search
{
    /**
     * Database name
     *
     * @access private
     * @var string
     */
    private $db;

    /**
     * Table Names
     *
     * @access private
     * @var array
     */
    private $tablesNamesOnly;

    /**
     * Type of search
     *
     * @access private
     * @var array
     */
    private $searchTypes;

    /**
     * Already set search type
     *
     * @access private
     * @var integer
     */
    private $criteriaSearchType;

    /**
     * Already set search type's description
     *
     * @access private
     * @var string
     */
    private $searchTypeDescription;

    /**
     * Search string/regexp
     *
     * @access private
     * @var string
     */
    private $criteriaSearchString;

    /**
     * Criteria Tables to search in
     *
     * @access private
     * @var array
     */
    private $criteriaTables;

    /**
     * Restrict the search to this column
     *
     * @access private
     * @var string
     */
    private $criteriaColumnName;

    /**
     * Public Constructor
     *
     * @param string $db Database name
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->searchTypes = array(
            '1' => __('at least one of the words'),
            '2' => __('all of the words'),
            '3' => __('the exact phrase as substring'),
            '4' => __('the exact phrase as whole field'),
            '5' => __('as regular expression'),
        );
        // Sets criteria parameters
        $this->setSearchParams();
    }

    /**
     * Sets search parameters
     *
     * @return void
     */
    private function setSearchParams()
    {
        $this->tablesNamesOnly = $GLOBALS['dbi']->getTables($this->db);

        if (empty($_REQUEST['criteriaSearchType'])
            || ! is_string($_REQUEST['criteriaSearchType'])
            || ! array_key_exists(
                $_REQUEST['criteriaSearchType'],
                $this->searchTypes
            )
        ) {
            $this->criteriaSearchType = 1;
            unset($_REQUEST['submit_search']);
        } else {
            $this->criteriaSearchType = (int) $_REQUEST['criteriaSearchType'];
            $this->searchTypeDescription
                = $this->searchTypes[$_REQUEST['criteriaSearchType']];
        }

        if (empty($_REQUEST['criteriaSearchString'])
            || ! is_string($_REQUEST['criteriaSearchString'])
        ) {
            $this->criteriaSearchString = '';
            unset($_REQUEST['submit_search']);
        } else {
            $this->criteriaSearchString = $_REQUEST['criteriaSearchString'];
        }

        $this->criteriaTables = array();
        if (empty($_REQUEST['criteriaTables'])
            || ! is_array($_REQUEST['criteriaTables'])
        ) {
            unset($_REQUEST['submit_search']);
        } else {
            $this->criteriaTables = array_intersect(
                $_REQUEST['criteriaTables'], $this->tablesNamesOnly
            );
        }

        if (empty($_REQUEST['criteriaColumnName'])
            || ! is_string($_REQUEST['criteriaColumnName'])
        ) {
            unset($this->criteriaColumnName);
        } else {
            $this->criteriaColumnName = $GLOBALS['dbi']->escapeString(
                $_REQUEST['criteriaColumnName']
            );
        }
    }

    /**
     * Builds the SQL search query
     *
     * @param string $table The table name
     *
     * @return array 3 SQL queries (for count, display and delete results)
     *
     * @todo    can we make use of fulltextsearch IN BOOLEAN MODE for this?
     * PMA_backquote
     * DatabaseInterface::freeResult
     * DatabaseInterface::fetchAssoc
     * $GLOBALS['db']
     * explode
     * count
     * strlen
     */
    private function getSearchSqls($table)
    {
        // Statement types
        $sqlstr_select = 'SELECT';
        $sqlstr_delete = 'DELETE';
        // Table to use
        $sqlstr_from = ' FROM '
            . Util::backquote($GLOBALS['db']) . '.'
            . Util::backquote($table);
        // Gets where clause for the query
        $where_clause = $this->getWhereClause($table);
        // Builds complete queries
        $sql = array();
        $sql['select_columns'] = $sqlstr_select . ' * ' . $sqlstr_from
            . $where_clause;
        // here, I think we need to still use the COUNT clause, even for
        // VIEWs, anyway we have a WHERE clause that should limit results
        $sql['select_count']  = $sqlstr_select . ' COUNT(*) AS `count`'
            . $sqlstr_from . $where_clause;
        $sql['delete']        = $sqlstr_delete . $sqlstr_from . $where_clause;

        return $sql;
    }

    /**
     * Provides where clause for building SQL query
     *
     * @param string $table The table name
     *
     * @return string The generated where clause
     */
    private function getWhereClause($table)
    {
        // Columns to select
        $allColumns = $GLOBALS['dbi']->getColumns($GLOBALS['db'], $table);
        $likeClauses = array();
        // Based on search type, decide like/regex & '%'/''
        $like_or_regex   = (($this->criteriaSearchType == 5) ? 'REGEXP' : 'LIKE');
        $automatic_wildcard   = (($this->criteriaSearchType < 4) ? '%' : '');
        // For "as regular expression" (search option 5), LIKE won't be used
        // Usage example: If user is searching for a literal $ in a regexp search,
        // he should enter \$ as the value.
        $criteriaSearchStringEscaped = $GLOBALS['dbi']->escapeString(
            $this->criteriaSearchString
        );
        // Extract search words or pattern
        $search_words = (($this->criteriaSearchType > 2)
            ? array($criteriaSearchStringEscaped)
            : explode(' ', $criteriaSearchStringEscaped));

        foreach ($search_words as $search_word) {
            // Eliminates empty values
            if (strlen($search_word) === 0) {
                continue;
            }
            $likeClausesPerColumn = array();
            // for each column in the table
            foreach ($allColumns as $column) {
                if (! isset($this->criteriaColumnName)
                    || strlen($this->criteriaColumnName) === 0
                    || $column['Field'] == $this->criteriaColumnName
                ) {
                    $column = 'CONVERT(' . Util::backquote($column['Field'])
                            . ' USING utf8)';
                    $likeClausesPerColumn[] = $column . ' ' . $like_or_regex . ' '
                        . "'"
                        . $automatic_wildcard . $search_word . $automatic_wildcard
                        . "'";
                }
            } // end for
            if (count($likeClausesPerColumn) > 0) {
                $likeClauses[] = implode(' OR ', $likeClausesPerColumn);
            }
        } // end for
        // Use 'OR' if 'at least one word' is to be searched, else use 'AND'
        $implode_str  = ($this->criteriaSearchType == 1 ? ' OR ' : ' AND ');
        if (empty($likeClauses)) {
            // this could happen when the "inside column" does not exist
            // in any selected tables
            $where_clause = ' WHERE FALSE';
        } else {
            $where_clause = ' WHERE ('
                . implode(') ' . $implode_str . ' (', $likeClauses)
                . ')';
        }
        return $where_clause;
    }

    /**
     * Displays database search results
     *
     * @return string HTML for search results
     */
    public function getSearchResults()
    {
        $html_output = '';
        // Displays search string
        $html_output .= '<br />'
            . '<table class="data">'
            . '<caption class="tblHeaders">'
            . sprintf(
                __('Search results for "<i>%s</i>" %s:'),
                htmlspecialchars($this->criteriaSearchString),
                $this->searchTypeDescription
            )
            . '</caption>';

        $num_search_result_total = 0;
        // For each table selected as search criteria
        foreach ($this->criteriaTables as $each_table) {
            // Gets the SQL statements
            $newsearchsqls = $this->getSearchSqls($each_table);
            // Executes the "COUNT" statement
            $res_cnt = intval($GLOBALS['dbi']->fetchValue($newsearchsqls['select_count']));
            $num_search_result_total += $res_cnt;
            // Gets the result row's HTML for a table
            $html_output .= $this->getResultsRow(
                $each_table, $newsearchsqls, $res_cnt
            );
        } // end for
        $html_output .= '</table>';
        // Displays total number of matches
        if (count($this->criteriaTables) > 1) {
            $html_output .= '<p>';
            $html_output .= sprintf(
                _ngettext(
                    '<b>Total:</b> <i>%s</i> match',
                    '<b>Total:</b> <i>%s</i> matches',
                    $num_search_result_total
                ),
                $num_search_result_total
            );
            $html_output .= '</p>';
        }
        return $html_output;
    }

    /**
     * Provides search results row with browse/delete links.
     * (for a table)
     *
     * @param string  $table         One of the tables on which search was performed
     * @param array   $newSearchSqls Contains SQL queries
     * @param integer $resultCount   Number of results found
     *
     * @return string HTML row
     */
    private function getResultsRow($table, array $newSearchSqls, $resultCount)
    {
        return Template::get('database/search/results_row')->render([
            'result_count' => $resultCount,
            'new_search_sqls' => $newSearchSqls,
            'db' => $GLOBALS['db'],
            'table' => $table,
        ]);
    }

    /**
     * Provides the main search form's html
     *
     * @return string HTML for selection form
     */
    public function getSelectionForm()
    {
        $html_output = '<a id="db_search"></a>';
        $html_output .= '<form id="db_search_form"'
            . ' class="ajax lock-page"'
            . ' method="post" action="db_search.php" name="db_search">';
        $html_output .= Url::getHiddenInputs($GLOBALS['db']);
        $html_output .= '<fieldset>';
        // set legend caption
        $html_output .= '<legend>' . __('Search in database') . '</legend>';
        $html_output .= '<table class="formlayout all100">';
        // inputbox for search phrase
        $html_output .= '<tr>';
        $html_output .= '<td class="right">' . __('Words or values to search for (wildcard: "%"):')
            . '</td>';
        $html_output .= '<td><input type="text"'
            . ' name="criteriaSearchString" class="all85"'
            . ' value="' . htmlspecialchars($this->criteriaSearchString) . '" />';
        $html_output .= '</td>';
        $html_output .= '</tr>';
        // choices for types of search
        $html_output .= '<tr>';
        $html_output .= '<td class="right vtop">' . __('Find:') . '</td>';
        $html_output .= '<td>';
        $choices = array(
            '1' => $this->searchTypes[1] . ' '
                . Util::showHint(
                    __('Words are separated by a space character (" ").')
                ),
            '2' => $this->searchTypes[2] . ' '
                . Util::showHint(
                    __('Words are separated by a space character (" ").')
                ),
            '3' => $this->searchTypes[3],
            '4' => $this->searchTypes[4],
            '5' => $this->searchTypes[5] . ' '
                . Util::showMySQLDocu('Regexp')
        );
        // 4th parameter set to true to add line breaks
        // 5th parameter set to false to avoid htmlspecialchars() escaping
        // in the label since we have some HTML in some labels
        $html_output .= Util::getRadioFields(
            'criteriaSearchType', $choices, $this->criteriaSearchType, true, false
        );
        $html_output .= '</td></tr>';
        // displays table names as select options
        $html_output .= '<tr>';
        $html_output .= '<td class="right vtop">' . __('Inside tables:') . '</td>';
        $html_output .= '<td rowspan="2">';
        $html_output .= '<select name="criteriaTables[]" class="all85"'
            . ' multiple="multiple">';
        foreach ($this->tablesNamesOnly as $each_table) {
            if (in_array($each_table, $this->criteriaTables)) {
                $is_selected = ' selected="selected"';
            } else {
                $is_selected = '';
            }
            $html_output .= '<option value="' . htmlspecialchars($each_table) . '"'
                . $is_selected . '>'
                . str_replace(' ', '&nbsp;', htmlspecialchars($each_table))
                . '</option>';
        } // end for
        $html_output .= '</select>';
        $html_output .= '</td></tr>';
        // Displays 'select all' and 'unselect all' links
        $alter_select = '<a href="#" '
            . 'onclick="setSelectOptions(\'db_search\','
            . ' \'criteriaTables[]\', true); return false;">'
            . __('Select all') . '</a> &nbsp;/&nbsp;';
        $alter_select .= '<a href="#" '
            . 'onclick="setSelectOptions(\'db_search\','
            . ' \'criteriaTables[]\', false); return false;">'
            . __('Unselect all') . '</a>';
        $html_output .= '<tr><td class="right vbottom">'
            . $alter_select . '</td></tr>';
        // Inputbox for column name entry
        $html_output .= '<tr>';
        $html_output .= '<td class="right">' . __('Inside column:') . '</td>';
        $html_output .= '<td><input type="text" name="criteriaColumnName" class="all85"'
            . 'value="'
            . (! empty($this->criteriaColumnName)
                ? htmlspecialchars($this->criteriaColumnName)
                : '')
            . '" /></td>';
        $html_output .= '</tr>';
        $html_output .= '</table>';
        $html_output .= '</fieldset>';
        $html_output .= '<fieldset class="tblFooters">';
        $html_output .= '<input type="submit" name="submit_search" value="'
            . __('Go') . '" id="buttonGo" />';
        $html_output .= '</fieldset>';
        $html_output .= '</form>';
        $html_output .= '<div id="togglesearchformdiv">'
            . '<a id="togglesearchformlink"></a></div>';

        return $html_output;
    }

    /**
     * Provides div tags for browsing search results and sql query form.
     *
     * @return string div tags
     */
    public function getResultDivs()
    {
        return Template::get('database/search/result_divs')->render();
    }
}
