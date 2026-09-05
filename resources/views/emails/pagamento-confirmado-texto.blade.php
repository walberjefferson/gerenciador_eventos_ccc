Olá, {{ $nome }}!

Recebemos o seu pagamento e a sua inscrição em {{ $evento }} está confirmada.
Guarde esta mensagem: ela é o seu comprovante.

Valor pago: {{ $valor }}
Pagamento reconhecido em: {{ $pagoEm }}
Inscrição: {{ $codigo }}
@if ($codigoIngressoFormatado)

SEU INGRESSO: {{ $codigoIngressoFormatado }}
Apresente este código na entrada. Ele vale para uma única entrada.
@endif
@if (count($atividades) > 0)

Atividades escolhidas:
@foreach ($atividades as $atividade)
- {{ $atividade }}
@endforeach
@endif

Para ver a sua inscrição, abra este endereço:
{{ $link }}

Nos vemos lá. Se precisar mudar alguma coisa, procure a organização do evento.
