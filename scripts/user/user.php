<?php
namespace scripts\user;
/*
 * user authentication system
 *
 * users will be logged in by just matching a hostmask or authing everytime they connect to irc, (or the bot does)
 * * on networks with services try to use irc caps to auth maybe? will require changing irc lib
 *
 * need a hostmask generator just make it *!*ident@fullhost
 *
 * user accounts will not have centralized settings, scripts can do their own set cmds etc and just have a user_id
 *
 * admin acl
 *
 * channel acl - later keeping it simple for now
 *
 * look at laravel gates for better idea on doing things with modular scripts
 *
 *
 * later modify Irc\Client to support ircv3 account-tag, add that ass optional auth engine per network
 *
 *
 * update other scripts that can use a user system
 *
 * todo: artbot put unauthed user arts in a guest dir
 * todo: cmdr needs to be able to do private msg commands, attr PrivCmd -done
 * possibly later add proper middlewares to cmdr, then those can be used for auth checks
 */

use knivey\cmdr\attributes\Cmd;
use knivey\cmdr\attributes\PrivCmd;
use knivey\cmdr\attributes\Options;
use \RedBeanPHP\R as R;

global $config;
$USERDB = uniqid();
$dbfile = $config["userdb"] ?? "user.db";
R::addDatabase($USERDB, "sqlite:{$dbfile}");

class User {

}

function auth($request) : User {

}

#[PrivCmd("register")]
function register($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {

}

#[PrivCmd("auth")]
function cmd_auth($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {

}

//a setting for if hostmask should be remembered or they need to auth every upon connection
#[PrivCmd("paranoid")]
function paranoid($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {

}

#[PrivCmd("pass")]
function pass($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {

}

//flags are acls in Access
#[PrivCmd("setflags")]
function addadmin($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {

}

#[PrivCmd("addflags")]
function addflags($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {

}

#[PrivCmd("delflags")]
function delflags($args, \Irc\Client $bot, \knivey\cmdr\Request $req) {

}
