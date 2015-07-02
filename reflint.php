#!/usr/bin/env php
<?php

// See https://github.com/nikic/PHP-Parser/blob/1.x/doc/2_Usage_of_basic_components.markdown

require 'vendor/autoload.php';
ini_set('xdebug.max_nesting_level', 3000);
use PhpParser\Node;

class NodeVisitor extends PhpParser\NodeVisitorAbstract
{
	public function setFilename($filename) {
		$this->filename = $filename;
	}
	public function leaveNode(Node $node) {
		global $OPTIONS;
		if ($node instanceof Node\Expr\FuncCall ||
			$node instanceof Node\Expr\MethodCall ||
			$node instanceof Node\Expr\StaticCall) {
			foreach ($node->args as $arg) {
				if ($arg->byRef) { # Runtime reference found in function/method/static call
					$node->setAttribute('comments', array()); // don't display comments
					$this->spew($node, "contains call with illegal runtime reference", $arg);
				}
			}
		}
		if ($node instanceof Node\Expr\FuncCall &&
			substr($nodename = $node->name->getLast(), 0, 5) == 'preg_') {

			$arg = $node->args[0]->value;
			$node->setAttribute('comments', array()); // don't display comments

			if ($arg instanceof Node\Scalar\String_) {
				if (preg_match('/\/\w*e\w*$/', $arg->value)) {
					$this->spew($node, "has eval-type regular expression, which is deprecated", $arg);
				}
			} elseif ($OPTIONS['warn']) {
				$this->spew($node, "has a regular expression parameter which is not a string literal "
				.	"and thus might contain /e. Please check manually", $arg);
			}
		}
	}
	protected function spew($arg, $message, $node = null) {
		global $PP;
		print $this->filename . ":" . $arg->getLine() . ": "
			. $PP->prettyPrint(array($arg)) . " "
			. $message
			. (($node !== null) ? ": " . $PP->prettyPrint(array($node)) : "")
			. "\n";
	}
}

function lint($file, $source)
{
	global $PARSER,$TRAVERSER,$OPTIONS,$DUMPER,$VISITOR;

	$VISITOR->setFilename($file);
	try {
		$stmts = $PARSER->parse($source);

		if ($OPTIONS['dump']) {
			echo $DUMPER->dump($stmts), "\n";
			return;
		}
		$TRAVERSER->traverse($stmts);
	} catch (PhpParser\Error $e) {
		return "$file: Parse Error: ". $e->getMessage();
	}
}

/* globals. */
$PARSER = new PhpParser\Parser(new PhpParser\Lexer);
$DUMPER = new PhpParser\NodeDumper;
$OPTIONS = array('dump' => false, 'recurse' => false, 'warn' => false);
$TRAVERSER = new PhpParser\NodeTraverser;
$VISITOR = new NodeVisitor;
$TRAVERSER->addVisitor($VISITOR);
$PP = new PhpParser\PrettyPrinter\Standard;

$errors = array();
$source = array();
$args = array();

foreach (array_slice($argv,1) as $arg) {
	if ($arg === "-d" || $arg == "--dump") {
		$OPTIONS['dump'] = true;
		continue;
	}
	if ($arg === "-r" || $arg == "--recurse") {
		$OPTIONS['recurse'] = true;
		continue;
	}
	if ($arg === "-W" || $arg == "--warn") {
		$OPTIONS['warn'] = true;
		continue;
	}
	if ($arg === "-h" || $arg == "--help") {
		echo "{$argv[0]} [--help|-h] [--dump|-d] file1.php ...\n\n";
		echo "--help|-h      print this message\n";
		echo "--dump|-d      dump AST instead of trying to lint it\n";
		echo "--warn|-W      Enable warnings which might be false positives\n";
		echo "--recurse|-r   Search directories in command line arguments for files ending in .php recursively and lint them all.\n";
		continue;
	}
	array_push($args, $arg);
}

if ($OPTIONS['recurse'] && count($args) == 0) {
	array_push($args, ".");
} elseif (count($args) == 0) {
	echo "No files.\n";
	exit(3);
}

foreach ($args as $arg) {
	if ($OPTIONS['recurse']) {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($arg));
		$files = new RegexIterator($files, '/\.php$/');
		foreach ($files as $file) {
			$code = @file_get_contents($file);
			if ($code === false)
				$errors[] = "Could not open input file $file";
			else
				$source[(string)$file] = $code;
		}
		continue;
	}

	$code = @file_get_contents($arg);
	if ($code === false)
		$errors[] = "Could not open input file $arg";
	else
		$source[$arg] = $code;
}

if (count($errors) !== 0) {
	file_put_contents('php://stderr', implode($errors,"\n")."\nAborted.\n");
	exit(1);
}

foreach ($source as $file => $code) {
	if ($l = lint($file, $code))
		$errors[] = $l;
}

if (count($errors) !== 0) {
	file_put_contents('php://stderr', "Syntax Errors:\n============\n".implode($errors,"\n"));
	exit(2);
}
