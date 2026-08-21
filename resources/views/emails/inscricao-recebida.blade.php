@extends('emails.moldura')

@section('titulo', 'Recebemos a sua inscrição')

@section('conteudo')
    <p style="margin:0 0 16px;">Olá, {{ $nome }}!</p>

    <p style="margin:0 0 16px;">
        A sua inscrição em <strong>{{ $evento }}</strong> foi registrada e a sua vaga está guardada.
        Falta só o pagamento para ela ficar confirmada.
    </p>

    <div style="margin:0 0 16px;padding:16px;border:1px solid #e3e3e3;border-radius:8px;">
        <p style="margin:0 0 4px;">Valor: <strong>{{ $valor }}</strong></p>
        <p style="margin:0;">Pague até <strong>{{ $prazo }}</strong>.</p>
    </div>

    <p style="margin:0 0 16px;">
        <a href="{{ $link }}" style="color:#0b6bb3;">Pagar a minha inscrição</a>
    </p>

    <p style="margin:0;color:#555555;">
        Se o pagamento não for feito até esse horário, a vaga volta para a fila e fica disponível
        para outra pessoa. Se já pagou, pode ignorar esta mensagem: assim que o pagamento for
        reconhecido, avisamos por e-mail.
    </p>
@endsection
