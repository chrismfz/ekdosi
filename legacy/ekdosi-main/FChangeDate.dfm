object FrmChangeDate: TFrmChangeDate
  Left = 0
  Top = 0
  BorderStyle = bsSingle
  Caption = #913#955#955#945#947#942' '#951#956#949#961#959#956#951#957#943#945#962
  ClientHeight = 38
  ClientWidth = 362
  Color = clBtnFace
  Font.Charset = DEFAULT_CHARSET
  Font.Color = clWindowText
  Font.Height = -11
  Font.Name = 'Tahoma'
  Font.Style = []
  FormStyle = fsMDIChild
  OldCreateOrder = False
  Visible = True
  PixelsPerInch = 96
  TextHeight = 13
  object Label2: TLabel
    Left = 12
    Top = 10
    Width = 92
    Height = 18
    Alignment = taRightJustify
    Caption = #919#956#949#961#959#956#951#957#943#945':'
    Font.Charset = GREEK_CHARSET
    Font.Color = clBlack
    Font.Height = -15
    Font.Name = 'Tahoma'
    Font.Style = [fsBold]
    ParentFont = False
  end
  object editDate: TJvDateEdit
    Left = 104
    Top = 8
    Width = 121
    Height = 21
    DotNetHighlighting = True
    AutoSize = False
    CalendarHints.Strings = (
      #917#960#953#955#941#958#964#949' '#951#956#949#961#959#956#951#957#943#945)
    CheckOnExit = True
    DefaultToday = True
    DialogTitle = #917#960#953#955#941#958#964#949' '#951#956#949#961#959#956#951#957#943#945
    Font.Charset = DEFAULT_CHARSET
    Font.Color = clWindowText
    Font.Height = -11
    Font.Name = 'Tahoma'
    Font.Style = [fsBold]
    ParentFont = False
    ShowNullDate = False
    TabOrder = 0
  end
  object cmdAuthentication: TJvDotNetButton
    Left = 248
    Top = 9
    Width = 96
    Height = 20
    Caption = #917#960#945#955#942#952#949#965#963#951
    Font.Charset = GREEK_CHARSET
    Font.Color = clBlack
    Font.Height = -15
    Font.Name = 'Tahoma'
    Font.Style = [fsBold, fsItalic]
    ParentFont = False
    TabOrder = 1
    OnClick = cmdAuthenticationClick
  end
end
