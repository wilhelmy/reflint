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
		global $PP;
		if ($node instanceof Node\Expr\FuncCall ||
			$node instanceof Node\Expr\MethodCall ||
			$node instanceof Node\Expr\StaticCall) {
			foreach ($node->args as $arg) {
				if ($arg->byRef) { # Runtime reference found in function/method/static call
					print $this->filename . ":".
						$arg->getLine() . ": " . $PP->prettyPrint(array($node)).
						" has illegal runtime reference to " .
						$PP->prettyPrint(array($arg)) . "\n";
				}
			}
		}
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
$OPTIONS = array('dump' => false);
$TRAVERSER = new PhpParser\NodeTraverser;
$VISITOR = new NodeVisitor;
$TRAVERSER->addVisitor($VISITOR);
$PP = new PhpParser\PrettyPrinter\Standard;

$errors = array();
$source = array();

foreach (array_slice($argv,1) as $arg) {
	if ($arg === "-d" || $arg == "--dump") {
		$OPTIONS['dump'] = true;
		continue;
	}
	# TODO add RecursiveIteratorIterator with RegexIterator('/\.php$/') here...
	if ($arg === "-h" || $arg == "--help") {
		echo "{$argv[0]} [--help|-h] [--dump|-d] file1.php ...\n\n";
		echo "--help|-h      print this message\n";
		echo "--dump|-d      dump AST instead of trying to lint it\n";
		continue;
	}
	
	$code = @file_get_contents($arg);
	if ($code === false)
		$errors[] = "Could not open input file $arg";
	else
		$source[$arg] = $code;
}

if (sizeof($errors) !== 0) {
	file_put_contents('php://stderr', implode($errors,"\n")."\nAborted.\n");
	exit(1);
}

foreach ($source as $file => $code) {
	if ($l = lint($file, $code))
		$errors[] = $l;
}

if (sizeof($errors) !== 0) {
	file_put_contents('php://stderr', implode($errors,"\n")."\nThere were syntax errors.\n");
	exit(2);
}
