@extends('emails.moldura')

@section('titulo', 'Sua inscrição foi cancelada')

@section('conteudo')
    <p style="margin:0 0 16px;">Olá, {{ $nome }}!</p>

    <p style="margin:0 0 16px;">
        A sua inscrição em <strong>{{ $evento }}</strong> foi cancelada pela organização do evento
        em <strong>{{ $canceladaEm }}</strong>. A vaga que estava reservada para você voltou para a fila.
    </p>

    @if ($haviaPagamento)
        <p style="margin:0 0 16px;">
            Se você já tinha pagado, procure a organização para combinar a devolução do valor.
        </p>
    @endif

    <p style="margin:0 0 16px;">
        Para entender o motivo ou pedir a inscrição de volta, fale com a organização — é ela quem
        pode explicar o caso e resolver.
    </p>

    @if ($contato !== null)
        <p style="margin:0 0 16px;">Contato da organização: {{ $contato }}</p>
    @endif

    <p style="margin:0;color:#555555;">
        <a href="{{ $link }}" style="color:#0b6bb3;">Ver a minha inscrição</a>
    </p>
@endsection
