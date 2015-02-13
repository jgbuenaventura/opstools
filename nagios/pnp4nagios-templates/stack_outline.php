<?php

# This script was initially developed by Infoxchange for internal use
# and has kindly been made available to the Open Source community for
# redistribution and further development under the terms of the
# GNU General Public License v2: http://www.gnu.org/licenses/gpl.html
# Copyright 2015 Infoxchange
# Author:  George Hansper <george@hansper.id.au>
#
# I found this on https://mail.endcrypt.com/pipermail/check_postgres/2009-March/000308.html
# Original posted by Cédric Villemain cedric.villemain at dalibo.com 
# Rewritten to support RRD_STORAGE_TYPE = MULTIPLE and make it a little more flexible
#
# Usage:
# Put this file in the PNP4Nagios templates directory, and provide eg. a check_disk.php template like this:
# <?php
#        $opt[1] = "--vertical-label \"bytes\"  --title \"Disk usage for $hostname\" -l 0 ";
#        require('stack_outline.php');
# ? >
# Or to render multiple values on one graph without stacking...
# <?php
#        $opt[1] = "--vertical-label \"bytes\"  --title \"Disk usage for $hostname\" -l 0 ";
#        $STACK=false;
#        require('stack_outline.php');
# ? >
#
# Other control variables:
#   $FILL = false;
#          ... draw line graphs only (not filled area graphs)
#   $LIMIT = 8;
#          ... at most $LIMIT data-sources per graph eg
#              eg. $LIMT=8 if there are 12 Data Sources, render the 1st 8 DS items on the 1st graph, and the nex4 on the second graph
#
# Any Data Source (perfdata label) named 'time' will be rendered separately below the stacked graph
# The 'time' DS is created by check_postgres and represents the execution time of the plugin
#

###############################################################################
# Common part for stacked graph with outline
###############################################################################

$palette = array(
    array('RED',    '#EA644A', '#CC3118'),
    array('YELLOW', '#ECD748', '#C9B215'),
    array('ORANGE', '#EC9D48', '#CC7016'),
    array('GREEN',  '#54EC48', '#24BC14'),
    array('PINK',   '#DE48EC', '#B415C7'),
    array('BLUE',   '#48C4EC', '#1598C3'),
    array('PURPLE', '#7648EC', '#4D18E4'),
    array('GREY',   '#C0C0C0', '#808080')
    );

if ( ! isset($def[1]) ) {
  $def = array();
}

if ( ! isset($LIMIT) ) {
  $LIMIT=count($DS);
}

if ( ! isset($FILL) ) {
  $FILL=true;
}
# Pre-populate the array so that they are displayed in the order 1..n
for( $ndx=1; $ndx*$LIMIT < (count($DS)+$LIMIT-1); $ndx++ ){
  $def[$ndx]='';
  $ds_name[$ndx]='';
  if ( $ndx != 1 ) {
    $opt[$ndx]=$opt[1];
  }
}

$def_n = 1;
# Graph index - DS index after ignoring the 'time' DS
$gr_ndx=0;
$cdef_line = '';

foreach ( array_keys($DS) as $ds_ndx ) {
  if ($NAME[$ds_ndx] == 'time' ) {
    $def_time =  floor( ( count($DS) + $LIMIT -2 ) / $LIMIT ) + 1; 
    $opt[$def_time] = "--title \"Execution time for $servicedesc on $hostname\" -l 0 ";
    $ds_name[$def_time] = $LABEL[$ds_ndx];
    $def[$def_time]    = rrd::def(   'exec_time',  $RRDFILE[$ds_ndx],                $DS[$ds_ndx],         "MAX");
    if ( $FILL ) {
      $def[$def_time] .= rrd::area(  'exec_time',  $palette[5][1],       $LABEL[$ds_ndx] );
      $def[$def_time] .= rrd::line1(  'exec_time',  $palette[5][2], '', ''       );
    } else {
      $def[$def_time] .= rrd::line1(  'exec_time',  $palette[5][2],       $LABEL[$ds_ndx] );
    }
    $def[$def_time]   .= rrd::gprint('exec_time',  array("LAST", "MIN", "AVERAGE", "MAX"),  "%6.3lg %s");
    continue;
  }

  $gr_ndx+=1;
  if ( $gr_ndx > $LIMIT ) {
    $gr_ndx=1;
    $opt[$def_n+1] = $opt[$def_n];
    $def[$def_n].= $cdef_line;
    $def_n++;
  }

  if ($gr_ndx >= 2  ) {
    if ( ! isset($STACK) || $STACK  == true ) {
      $TYPE="STACK";
    } else {
      # Not stacked data
      $TYPE='';
    }
  } else {
    $def[$def_n] = '';
    $ds_name[$def_n] = '';
    $TYPE='';
    $cdef_line = '';
  }

  switch( substr($UNIT[$ds_ndx],0,1) ) {
    case 'T' : $unit_mutiplier = 1099511627776; break;
    case 'G' : $unit_mutiplier = 1073741824; break;
    case 'M' : $unit_mutiplier = 1048576; break;
    case 'k' : $unit_mutiplier = 1024; break;
    default  : $unit_mutiplier = 1; break;
  }

  # Area

  # The cdef is used to convert the original data in k,M,G etc back to base units, so rrdtool can do it's own unit rendering
  $def[$def_n]    .= rrd::def(   "raw_{$gr_ndx}",  $RRDFILE[$ds_ndx],                $DS[$ds_ndx],         "MAX");
  $def[$def_n]    .= rrd::cdef(  "var_{$gr_ndx}",  "raw_{$gr_ndx},$unit_mutiplier,*" );
  if ( $FILL ) {
    $def[$def_n]  .= rrd::area(  "var_{$gr_ndx}",  $palette[($gr_ndx-1)%8][1],       sprintf('%-15s',$LABEL[$ds_ndx]),  $TYPE);
  } else {
    $def[$def_n]  .= rrd::line1( "var_{$gr_ndx}",  $palette[($gr_ndx-1)%8][2],       sprintf('%-15s',$LABEL[$ds_ndx]),  $TYPE);
  }
  $def[$def_n]    .= rrd::gprint("var_{$gr_ndx}",  array("LAST", "AVERAGE", "MAX"),  "%6.3lg %s");
  if ( strlen($ds_name[$def_n]) < 50 ) {
    $ds_name[$def_n] .= "$LABEL[$ds_ndx] ";
  } else if ( substr($ds_name[$def_n],-3,3) != '...' ) {
    $ds_name[$def_n] .= "...";
  }

  # Line

  # Draw a line, too, for each area graph, as an outline
  # These lines go last, so they don't get drawn over by the area graphs
  # The label is set to '' so these lines do not appear in the legend
  # The cdef is there to omit line segments with value 0, to prevent 'uninteresting' lines from overwriting the previous line
  # The value 'UNKN' is plotted as an empty space
  if ( $FILL ) {
    if ( ! isset($STACK) || $STACK == true ) {
      $cdef_line .= rrd::cdef( "line_{$gr_ndx}", "var_{$gr_ndx},var_{$gr_ndx},UNKN,IF" );
      $cdef_line .= rrd::line1( "line_{$gr_ndx}", $palette[($gr_ndx-1)%8][2], '', $TYPE);
    } else {
      $cdef_line .= rrd::line1( "var_{$gr_ndx}", $palette[($gr_ndx-1)%8][2], '', $TYPE);
    }
  }

}

$def[$def_n].= $cdef_line;

?>