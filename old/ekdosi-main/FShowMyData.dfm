object FrmShowMyData: TFrmShowMyData
  Left = 0
  Top = 0
  Caption = 'myData'
  ClientHeight = 502
  ClientWidth = 785
  Color = clBtnFace
  Font.Charset = DEFAULT_CHARSET
  Font.Color = clWindowText
  Font.Height = -11
  Font.Name = 'Tahoma'
  Font.Style = []
  FormStyle = fsMDIChild
  OldCreateOrder = False
  Visible = True
  DesignSize = (
    785
    502)
  PixelsPerInch = 96
  TextHeight = 13
  object JvDotNetButton1: TJvDotNetButton
    Left = 565
    Top = 469
    Width = 212
    Height = 25
    Anchors = [akRight, akBottom]
    Caption = #923#942#968#951' '#945#960#949#963#964#945#955#956#941#957#969#957' '#960#945#961#945#963#964#945#964#953#954#974#957
    TabOrder = 0
    OnClick = JvDotNetButton1Click
  end
  object invList: TJvStringGrid
    Left = 8
    Top = 1
    Width = 769
    Height = 248
    Anchors = [akLeft, akTop, akRight]
    ColCount = 6
    DefaultColWidth = 100
    FixedCols = 0
    RowCount = 1
    FixedRows = 0
    Options = [goFixedVertLine, goFixedHorzLine, goVertLine, goHorzLine, goRangeSelect, goTabs, goRowSelect]
    TabOrder = 1
    Alignment = taLeftJustify
    FixedFont.Charset = DEFAULT_CHARSET
    FixedFont.Color = clWindowText
    FixedFont.Height = -11
    FixedFont.Name = 'Tahoma'
    FixedFont.Style = []
    ColWidths = (
      100
      100
      100
      100
      100
      100)
    RowHeights = (
      24)
  end
  object cancelledInvList: TJvStringGrid
    Left = 8
    Top = 255
    Width = 769
    Height = 201
    Anchors = [akLeft, akTop, akRight]
    ColCount = 3
    DefaultColWidth = 100
    FixedCols = 0
    RowCount = 1
    FixedRows = 0
    Options = [goFixedVertLine, goFixedHorzLine, goVertLine, goHorzLine, goRangeSelect, goRowSelect]
    TabOrder = 2
    Alignment = taLeftJustify
    FixedFont.Charset = DEFAULT_CHARSET
    FixedFont.Color = clWindowText
    FixedFont.Height = -11
    FixedFont.Name = 'Tahoma'
    FixedFont.Style = []
    ColWidths = (
      135
      119
      128)
    RowHeights = (
      24)
  end
  object NetHTTPClient1: TNetHTTPClient
    Asynchronous = False
    ConnectionTimeout = 60000
    ResponseTimeout = 60000
    AllowCookies = True
    HandleRedirects = True
    UserAgent = 'Embarcadero URI Client/1.0'
    Left = 688
    Top = 48
  end
  object httpTrans: TNetHTTPRequest
    Asynchronous = False
    ConnectionTimeout = 60000
    ResponseTimeout = 60000
    Client = NetHTTPClient1
    OnRequestCompleted = httpTransRequestCompleted
    Left = 728
    Top = 44
  end
end
