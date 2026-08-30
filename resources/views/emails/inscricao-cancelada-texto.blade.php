Olá, {{ $nome }}!

A sua inscrição em {{ $evento }} foi cancelada pela organização do evento em
{{ $canceladaEm }}. A vaga que estava reservada para você voltou para a fila.
@if ($haviaPagamento)

Se você já tinha pagado, procure a organização para combinar a devolução do valor.
@endif

Para entender o motivo ou pedir a inscrição de volta, fale com a organização — é
ela quem pode explicar o caso e resolver.
@if ($contato !== null)

Contato da organização: {{ $contato }}
@endif

Para ver a sua inscrição, abra este endereço:
{{ $link }}
