<?php

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

function respond(Response $response, int $statusCode = 200, ?array $return = null) {
	if ($return !== null) {
		$response->write(json_encode($return));
	}

	$response->header("Content-type", "application/json");
	$response->status($statusCode);
	$response->end();

	return $response;
}

function getConnection(): PDO {
	$dsn = sprintf(
		"pgsql:host=%s;port=%s;dbname=%s",
		getenv("DB_HOST"),
		getenv("DB_PORT"),
		getenv("DB_NAME"),
	);

	return new PDO(
		$dsn,
		getenv("DB_USER"),
		getenv("DB_PASSWORD"),
		[PDO::ATTR_PERSISTENT => true]
	);
}

function getQueryTransacao(string $tipo): string {
	if ($tipo === "c") {
		$query = "UPDATE cliente SET saldo = saldo + ? WHERE id = ? RETURNING saldo, limite";
	} else {
		$query = "UPDATE cliente SET saldo = saldo - ? WHERE id = ? AND saldo - ? >= -ABS(limite) RETURNING saldo, limite";
	}

	return (
		"WITH cliente_atualizado AS ($query) " .
		"INSERT INTO transacao (cliente_id, valor, tipo, descricao, limite_atual, saldo_atual) " .
			"SELECT ?, ?, ?, ?, cliente_atualizado.limite, cliente_atualizado.saldo " .
			"FROM cliente_atualizado " .
			"RETURNING limite_atual, saldo_atual"
	);
}

function handleTransacao(Response $response, int $clienteId, $body) {
	if (empty($body) || !is_array($body)) {
		return respond($response, 422);
	}

	if (filter_var($body["valor"], FILTER_VALIDATE_INT) === false || $body["valor"] <= 0) {
		return respond($response, 422);
	}

	if ($body["tipo"] !== "c" && $body["tipo"] !== "d") {
		return respond($response, 422);
	}

	if (empty($body["descricao"])) {
		return respond($response, 422);
	}

	$descricaoLen = strlen($body["descricao"]);
	if ($descricaoLen < 1 || $descricaoLen > 10) {
		return respond($response, 422);
	}
	
	if ($body["tipo"] == "c") {
		$params = [$body["valor"], $clienteId];
	} else {
		$params = [$body["valor"], $clienteId, $body["valor"]];
	}

	$params = array_merge($params, [$clienteId, $body["valor"], $body["tipo"], $body["descricao"]]);
	$query = getQueryTransacao($body["tipo"]);

	$stmt = getConnection()->prepare($query);
	$stmt->execute($params);
	$result = $stmt->fetchAll();

	if (empty($result) || empty($result[0])) {
		return respond($response, 422);
	}

	$return = [
		"saldo" => $result[0]["saldo_atual"],
		"limite" => $result[0]["limite_atual"],
	];

	return respond($response, 200, $return);
}

function handleExtrato(Response $response, int $clienteId) {
	$query = (
		"SELECT valor, tipo, descricao, realizada_em, limite_atual, saldo_atual " .
		"FROM transacao " .
		"WHERE cliente_id = ? " .
		"ORDER BY id DESC " .
		"LIMIT 11 " // Deve pegar uma a mais para ignorar a inicial depois, se necessário
	);

	$conn = getConnection();
	$stmt = $conn->prepare($query);
	$stmt->execute([$clienteId]);
	$result = $stmt->fetchAll();

	if (empty($result)) {
		return respond($response, 422);
	}

	$limiteAtual = $result[0]["limite_atual"];
	$saldoAtual = $result[0]["saldo_atual"];

	// Sempre remove a última transação
	// Se tem 11 transações, remove e fica com 10 (nenhuma sendo a inicial)
	// Se tem menos, remove a última, que será sempre o saldo inicial
	array_pop($result);

	$ultimasTransacoes = [];
	foreach ($result as $row) {
		$ultimasTransacoes[] = [
			"valor" => $row["valor"],
			"tipo" => $row["tipo"],
			"descricao" => $row["descricao"],
			"realizada_em" => $row["realizada_em"],
		];
	}

	$return = [
		"saldo" => [
			"total" => $saldoAtual,
			"limite" => $limiteAtual,
			"data_extrato" => date("Y-m-d H:i:s"), // verificar essa data
		],
		"ultimas_transacoes" => $ultimasTransacoes,
	];

	return respond($response, 200, $return);
}

function handleCliente(Request $request, Response $response) {
	$path = $request->server["request_uri"];
 
	$uriParts = explode("/", $path);
	if (count($uriParts) != 4) {
		return respond($response, 404);
	}

	$id = $uriParts[2];
	if (filter_var($id, FILTER_VALIDATE_INT) === false) {
		return respond($response, 422);
	}

	// Regra de negócio da aplicação
	if ($id < 1 || $id > 5) {
		return respond($response, 404);
	}

	$method = $request->server["request_method"];
	if ($uriParts[3] === "transacoes") {
		if ($method !== "POST") {
			return respond($response, 404);
		}

		$body = json_decode((string) $request->rawcontent(), true);

		return handleTransacao($response, $id, $body);
	} else if ($uriParts[3] === "extrato") {
		if ($method !== "GET") {
			return respond($response, 404);
		}

		return handleExtrato($response, $id);
	}

	return respond($response, 404);
}

$serverPort = getenv("SERVER_PORT");
$server = new Server("0.0.0.0", $serverPort);

$server->on("request", static function (Request $request, Response $response) {
	return handleCliente($request, $response);
});

$server->on("start", static function () use ($serverPort) {
	echo "Rodando servidor na porta " . $serverPort;
});

$server->start();
