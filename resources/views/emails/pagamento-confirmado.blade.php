@extends('emails.moldura')

@section('titulo', 'Pagamento confirmado')

@section('conteudo')
    <p style="margin:0 0 16px;">Olá, {{ $nome }}!</p>

    <p style="margin:0 0 16px;">
        Recebemos o seu pagamento e a sua inscrição em <strong>{{ $evento }}</strong> está
        <strong>confirmada</strong>. Guarde esta mensagem: ela é o seu comprovante.
    </p>

    <div style="margin:0 0 16px;padding:16px;border:1px solid #e3e3e3;border-radius:8px;">
        <p style="margin:0 0 4px;">Valor pago: <strong>{{ $valor }}</strong></p>
        <p style="margin:0 0 4px;">Pagamento reconhecido em: <strong>{{ $pagoEm }}</strong></p>
        <p style="margin:0;">Inscrição: <strong>{{ $codigo }}</strong></p>
    </div>

    @if (count($atividades) > 0)
        <p style="margin:0 0 8px;">Atividades escolhidas:</p>
        <ul style="margin:0 0 16px;padding-left:20px;">
            @foreach ($atividades as $atividade)
                <li>{{ $atividade }}</li>
            @endforeach
        </ul>
    @endif

    <p style="margin:0 0 16px;">
        <a href="{{ $link }}" style="color:#0b6bb3;">Ver a minha inscrição</a>
    </p>

    <p style="margin:0;color:#555555;">
        Nos vemos lá. Se precisar mudar alguma coisa, procure a organização do evento.
    </p>
@endsection
