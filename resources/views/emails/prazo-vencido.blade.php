@extends('emails.moldura')

@section('titulo', 'O prazo da sua inscrição venceu')

@section('conteudo')
    <p style="margin:0 0 16px;">Olá, {{ $nome }}!</p>

    <p style="margin:0 0 16px;">
        O prazo para pagar a sua inscrição em <strong>{{ $evento }}</strong> terminou em
        <strong>{{ $prazo }}</strong> e o pagamento não chegou até lá. Por isso a inscrição foi
        encerrada e a vaga voltou para a fila, para que outra pessoa possa usá-la.
    </p>

    <p style="margin:0 0 16px;">
        Se ainda quiser participar, é só se inscrever de novo — desde que ainda haja vaga.
    </p>

    <p style="margin:0 0 16px;">
        <a href="{{ $link }}" style="color:#0b6bb3;">Ver o evento e tentar de novo</a>
    </p>

    <p style="margin:0;color:#555555;">
        Se você já pagou e mesmo assim recebeu esta mensagem, procure a organização do evento:
        pode ser que o pagamento tenha sido reconhecido depois do prazo.
    </p>
@endsection
