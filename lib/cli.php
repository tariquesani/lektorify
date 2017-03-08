<?php

if ( defined('WP_CLI') && WP_CLI ) {

  class Lektor_Export_Command extends WP_CLI_Command {

    function __invoke() {
      global $lektorify;
      $lektorify->export();
    }
  }

  WP_CLI::add_command( 'lektorify', 'Lektor_Export_Command' );

}
