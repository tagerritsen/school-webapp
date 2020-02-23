<?php
/**
 * SPDX-License-Identifier: BSD-2-Clause
 *
 * ================================================================================
 * |                                Legal Section                                 |
 * ================================================================================
 * Copyright (C) 2020 Tristan Gerritsen
 * Copyright (C) 2020 Arend Top
 *
 * All Rights Reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice, this
 *    list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * ================================================================================
 * |                             Programming Language                             |
 * ================================================================================
 * Unfortunately, I have to use PHP for this project. This is because a CGI
 * solution creates either too much overhead or the code becomes too bloated.
 *
 * ================================================================================
 * |                                  Code Style                                  |
 * ================================================================================
 * 1. OOP is harmful
 *    In this program I try to reduce the amount of OOP. This is because a lot of
 *    people (me included) believe that the "Object Oriented Programming" paradigm
 *    is inherently harmhul. One of the reasons is that encapsulating everything in
 *    classes and small ubroutines/methods is a very bad idea, because it results
 *    in poor  readability and often results in actual worse performance. For more
 *	  information see this compiled overview here:
 *    https://en.wikipedia.org/wiki/Object-oriented_programming#Criticism
 *
 * 2. "Flat is better than nested." - Tim Peters
 *    I am also trying to reduce the amount of nested code. Because this:
 *
 *    function try() {
 *      if (!a)
 *        return result1
 *      if (!b)
 *        return result2
 *      if (!c)
 *        return result3
 *      if (!d)
 *        return result4
 *
 *      return result_success
 *    }
 *
 *    is inherently better than this:
 *
 *    function try() {
 *      if (a)
 *        if (b)
 *          if (c)
 *            if (d)
 *              return result_success
 *            else
 *              return result4
 *          else
 *            return result3
 *        else
 *          return result2
 *      else
 *        return result1
 *    }
 *
 *    Advantages of the first (flat approach):
 *    1. Maintenance: adding a 5th check or removing one is easier in the first.
 *    2. More easily understood (Readability counts)
 *
 * 3. Naming
 *    Historically, I've used snake_case for every type, function, etc. I have to
 *    admit that this decision was bad. Now I'm using UpperCamelCase for function,
 *    enum and type names, lowerCamelCase for variables, and UPPER_SNAKE_CASE for
 *    for constants.
 */
declare(strict_types=1);

# Section: Macro-like values
$EXIT_SUCCESS = 0; // Return value on success for exit()
$EXIT_FAILURE = 1; // Return value on failure for exit()

/**
 * There isn't a enum type in PHP, so I am using a abstract-class enum-like
 * solution. The enum values resolve into an integer, and cannot be type-checked
 * (i.e. checking for a valid enum), so an integer of value 5826828 is technically
 * correct but doesn't correspond to a correct enum type.
 */
abstract class Status {
	const Success = 0;
	const InvalidInput = 1;
	const InvalidLength = 2;
	const InvalidUsernameRegex = 3;
	const CommunicationErrorConnect = 4; // MySQLi connection error
	const CommunicationErrorQuery = 5; // MySQLi statement error
	const CommunicationErrorToken = 6; // MySQLi error on token insert
	const InvalidCredentials = 7;
}

# Global scope variables
$mysqli = null;

# Section: Functions
/**
 * Description:
 * This function will print out the status and kill the execution of this script.
 *
 * Warning:
 *   Code execution ends after a call to this function, and the code won't jump
 *   back to the caller.
 *
 * Parameter(s):
 *   int
 *     The status code. This should be retrieved from the Status enum-like class.
 *   ?string
 *     (Optional) The token. Setting this as NULL is effectively the same as
 *     omitting the parameter.
 *
 */
function PrintResultAndExit(int $status, ?string $token = null) : void {
	header("Content-Type: application/json;charset=utf-8");
	echo "{\"status\":" . $status . ($token === null ? "}" : (",\"token\":\"" . htmlentities($token) . "\"}"));
	global $mysqli;

	if ($mysqli !== null) {
		$mysqli->close();
		$mysqli = null;
	}

	global $EXIT_FAILURE;
	exit($EXIT_FAILURE);
}

/**
 * Description:
 *   This function will setup a connection to the SQL database server and will
 *   store the 'connection' into the global '$mysqli' variable.
 *
 * Parameter(s):
 *   void
 *
 * Return Value:
 *   (boolean) Success Status
 */
function SetupMysql() : bool {
	global $mysqli;
	$mysqli = new mysqli("?", "?", "?", "?");
	return $mysqli != false;
}

/**
 * Description:
 *   This function will check the credentials to the server.
 *
 * Parameter(s):
 *   string
 *     The username to check.
 *   string
 *     The password that supposedly corresponds to the username.
 *
 * Requirement(s):
 *   - SetupMysql() should've been called.
 *   - The call to SetupMysql() should've been successful (i.e. return value wasn't
 *     false).
 *   - The $mysqli variable was initialized.
 *   - $mysqli is of type "mysqli".
 *   - The connection to the SQL server is alive.
 *
 * Return Value:
 *   A null-string on failure, otherwise the UserID (i.e. in the format of a Guid).
 */
function CheckCredentials(string $username, string $password) : ?string {
	global $mysqli;
	$result = "";
	$statement = $mysqli->prepare("SELECT `Users`.`UserId` FROM `Users` WHERE `Username` = ?");
	$statement->bind_param("s", $username);
	$statement->bind_result($result);

	if (!$statement->execute()) {
		echo "DEBUG: Warning: \$statement->execute failed in CheckCredentials(...)!";
		PrintResultAndExit(Status::CommunicationErrorQuery);
	}

	if (!$statement->fetch()) {
		$statement->close();
		return null;
	}

	/** Not sure if the copy is needed, and if this isn't neccesary this doesn't
	  * even really make that much of a difference. */
	$resultCopy = $result;
	$statement->close();

	return $resultCopy;
}

/**
 * Description:
 *   This function will hash the password.
 *
 * Parameter(s):
 *   string
 *     The password to hash.
 *
 * Return Value:
 *   A non-null hashed password.
 */
function HashPassword(string $input) : string {
	$options = ['cost' => 12, ];
	return password_hash($input, PASSWORD_BCRYPT, $options);
}

/**
 * Description:
 *   This function will create a token.
 *
 * Parameter(s):
 *   void
 *
 * Return Value:
 *   A non-null string of length 128.
 */
function CreateToken() : string {
	$length = 128;
	$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
	$charactersLength = strlen($characters);
	$randomString = '';
	for ($i = 0; $i < $length; $i++) {
		$randomString .= $characters[rand(0, $charactersLength - 1) ];
	}
	return $randomString;
}

/**
 * Description:
 *   This function will create an unique token, inserts it into the "18h_arendt_db/
 *   Users" table and returns the value.
 *
 * Requirement(s):
 *   - SetupMysql() should've been called.
 *   - The call to SetupMysql() should've been successful (i.e. return value wasn't
 *     false).
 *   - The $mysqli variable was initialized.
 *   - $mysqli is of type "mysqli".
 *   - The connection to the SQL server is alive.
 *
 * Recommendations:
 *   - A call to CheckCredentials() with the corresponding UserID was successful.
 *
 * Parameter(s):
 *   string
 *     The UserId, (recommendation: this should be the return value of
 *     CheckCredentials(...).
 *
 * Return Value:
 *   NULL on failure, otherwise the unique token.
 */
function CreateInsertedToken(string $userId) : ?string {
	global $mysqli;
	$statementCheck = $mysqli->prepare("SELECT `Token` FROM `Tokens` WHERE `Token` = ?");

	$token = null;
	do {
		$token = createToken();
		$statementCheck->bind_param("s", $token);

		if (!$statementCheck->execute()) {
			echo "CreateInsertedToken failure on \$statementCheck->execute().<br>";
			var_dump($mysqli->error);
			PrintResultAndExit(Status::CommunicationErrorQuery);
		}

		if ($statementCheck->num_rows === 0)
			break;

		$statementCheck->close();
	} while (true);
	$statementCheck->close();

	$statementInsert = $mysqli->prepare(
		"INSERT INTO `Tokens` (`Token`, `UserID`) VALUES (?, ?)");
	$statementInsert->bind_param("ss", $token, $userId);
	if (!$statementInsert->execute()) {
		echo "CreateInsertedToken failure on \$statementInsert->execute().<br>\n";
		var_dump($mysqli->error);
		PrintResultAndExit(Status::CommunicationErrorToken);
	}

	$statementInsert->close();
	return $token;
}

# Section: Main program entrypoint.
if (!isset($_POST["username"]) || !isset($_POST["password"]))
	PrintResultAndExit(Status::InvalidInput);

$username = $_POST["username"];
$password = $_POST["password"];
$usernameLength = strlen($username);
$passwordLength = strlen($password);

/** Check credential requirements. */
if ($usernameLength < 3 || $usernameLength > 21 ||
	$passwordLength < 8 || $passwordLength > 255)
	PrintResultAndExit(Status::InvalidLength);

/** Check if the username is valid. */
if (!preg_match('/^[a-zA-Z]*$/', $username))
	PrintResultAndExit(Status::InvalidUsername);

/** Try to connect to MySQL using MySQLi. */
if (!SetupMysql())
	PrintResultAndExit(Status::CommunicationError);

/** Hash the password. */
$hashedPassword = HashPassword($password);

/** Check the credentials (i.e. this is often what people refer to when they are 
  * talking about a "Sign-In" or "Log-In". */
$userId = CheckCredentials($username, $hashedPassword);
if ($userId === null)
	PrintResultAndExit(Status::InvalidCredentials);

/** Create an unique token and insert it into the database. */
$token = CreateInsertedToken($userId);

/** The user is "Signed-In". Print the token and exit. */
PrintResultAndExit(Status::Success, $token);
?>
